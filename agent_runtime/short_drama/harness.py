"""Isolated Deep Agents candidate. Produces proposals, never business effects.

No credentials, HTTP endpoints, filesystem mounts or production DB access.
PHP remains authoritative for tenancy, billing, confirmations and execution.
The host supplies an authorized model adapter; this module never creates one.
"""
from deepagents import create_deep_agent
from langchain.agents.middleware import AgentMiddleware
from langchain_core.tools import tool
from langgraph.checkpoint.memory import InMemorySaver


class DomainToolsOnly(AgentMiddleware):
    """Do not accidentally grant the SDK's generic shell/filesystem/subagent tools."""

    def wrap_model_call(self, request, handler):
        allowed = [t for t in request.tools if getattr(t, "name", None) == "propose_decision"]
        return handler(request.override(tools=allowed))

    def wrap_tool_call(self, request, handler):
        if request.tool_call["name"] != "propose_decision":
            raise ValueError("Tool is not part of the short-drama capability contract")
        return handler(request)


@tool(return_direct=True)
def propose_decision(reply_markdown: str, action: dict, remember: list[dict]) -> dict:
    """Return the reply, one short-drama action and source-backed memory proposals.

    This records a proposal only. The PHP decision parser validates the action
    against the frozen run, then atomically commits reply, memory and effects.
    Do not call this tool again to retry an uncertain production submission.
    """
    return {"reply_markdown": reply_markdown, "action": action, "remember": remember}


def build_agent(model, *, checkpointer=None, instruction: str):
    return create_deep_agent(
        model=model,
        tools=[propose_decision],
        system_prompt=instruction,
        middleware=[DomainToolsOnly()],
        checkpointer=checkpointer or InMemorySaver(),
        subagents=[],
        name="short-drama-decision-candidate",
    )


def session_config(tenant: int, user: int, canvas: int, thread: int):
    if any(type(value) is not int or value <= 0 for value in (tenant, user, canvas, thread)):
        raise ValueError("Positive scoped identifiers required")
    return {"configurable": {"thread_id": f"{tenant}:{user}:{canvas}:{thread}"}, "recursion_limit": 6}
