"""Private stdio runtime. PHP owns the durable snapshot and all metered I/O.

No HTTP listener, API keys or business database credentials. Each process runs
one graph from the frozen conversation snapshot, then exits. PHP messages,
workflow, memory and run events are the durable checkpoint across restarts.
"""
import json
import sys
from langchain_core.language_models.chat_models import BaseChatModel
from langchain_core.messages import AIMessage, HumanMessage, ToolMessage
from langchain_core.outputs import ChatGeneration, ChatResult
from harness import build_agent, session_config

MAX_FRAME = 2 * 1024 * 1024


def send(frame):
    sys.stdout.write(json.dumps(frame, ensure_ascii=False) + "\n")
    sys.stdout.flush()


def receive():
    line = sys.stdin.readline(MAX_FRAME + 1)
    if not line.endswith("\n") or len(line.encode()) > MAX_FRAME:
        raise ValueError("Invalid runtime frame")
    value = json.loads(line)
    if not isinstance(value, dict):
        raise ValueError("Invalid runtime object")
    return value


class MeteredSnapshotModel(BaseChatModel):
    """Delegate once to the PHP adapter bound to this authorized run snapshot.

    Provider transport stays in PHP (including multimodal input, SSE, pricing,
    cancellation and fallback). PHP validates the envelope before settlement;
    we project it into the SDK's typed terminal proposal tool.
    """
    calls: int = 0

    @property
    def _llm_type(self):
        return "php-metered-snapshot"

    def bind_tools(self, tools, **kwargs):
        if [getattr(t, "name", "") for t in tools] != ["propose_decision"]:
            raise ValueError("Unsupported domain tools")
        return self

    def _generate(self, messages, stop=None, run_manager=None, **kwargs):
        if self.calls:
            raise ValueError("Paid model budget exhausted")
        self.calls += 1
        send({"type": "model_request", "sequence": self.calls})
        frame = receive()
        if frame.get("type") != "model_result" or frame.get("sequence") != self.calls:
            raise ValueError("Unexpected model receipt")
        decision = frame["decision"]
        return ChatResult(generations=[ChatGeneration(message=AIMessage(
            content="", tool_calls=[{"name": "propose_decision", "args": decision, "id": "decision-1"}]
        ))])


def run(frame):
    scope = frame["scope"]
    config = session_config(scope["tenant"], scope["user"], scope["canvas"], scope["thread"])
    messages = []
    for message in frame["messages"]:
        role, content = message["role"], message["content"]
        if role not in ("user", "assistant") or not isinstance(content, str):
            raise ValueError("Invalid frozen history")
        messages.append((HumanMessage if role == "user" else AIMessage)(content=content))
    if not messages or not isinstance(messages[-1], HumanMessage):
        raise ValueError("Missing current user message")
    model = MeteredSnapshotModel()
    agent = build_agent(model, instruction=frame["instruction"])
    output = agent.invoke({"messages": messages}, config)
    proposals = [m for m in output["messages"] if isinstance(m, ToolMessage) and m.name == "propose_decision"]
    if len(proposals) != 1 or model.calls != 1 or proposals[0].status != "success":
        raise ValueError("Invalid terminal proposal")
    return json.loads(proposals[0].content)


def main():
    send({"type": "ready", "protocol": 1, "engine": "deepagents_v1"})
    if "--health" in sys.argv:
        return
    try:
        decision = run(receive())
        send({"type": "complete", "decision": decision, "model_calls": 1})
    except Exception:
        # Never leak prompts, traceback, source material or provider credentials.
        send({"type": "error", "code": "AGENT_RUNTIME_FAILED"})
        raise SystemExit(1)


if __name__ == "__main__":
    main()
