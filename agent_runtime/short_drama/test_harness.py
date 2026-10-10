import json
import unittest
from typing import Any
from langchain_core.language_models.chat_models import BaseChatModel
from langchain_core.messages import AIMessage, HumanMessage, ToolMessage
from langchain_core.outputs import ChatGeneration, ChatResult
from harness import build_agent, session_config


class ReplayModel(BaseChatModel):
    responses: list[AIMessage]
    calls: list[Any] = []
    index: int = 0
    bound_tools: list[str] = []

    @property
    def _llm_type(self):
        return "offline-replay"

    def bind_tools(self, tools, **kwargs):
        self.bound_tools = [getattr(t, "name", "") for t in tools]
        return self

    def _generate(self, messages, stop=None, run_manager=None, **kwargs):
        self.calls.append(messages)
        response = self.responses[self.index]
        self.index += 1
        return ChatResult(generations=[ChatGeneration(message=response)])


def proposal(name="respond", reply="你好，有什么想聊的？"):
    return {"reply_markdown": reply, "action": {"name": name, "arguments": {}}, "remember": []}


class HarnessTest(unittest.TestCase):
    def test_conversation_checkpoint_and_single_proposal_without_business_effects(self):
        decision = proposal()
        model = ReplayModel(responses=[
            AIMessage(content="", tool_calls=[{"name": "propose_decision", "args": decision, "id": "d1"}]),
            AIMessage(content="", tool_calls=[{"name": "propose_decision", "args": proposal(reply="我们可以先聊故事。"), "id": "d2"}]),
            AIMessage(content="", tool_calls=[{"name": "propose_decision", "args": proposal(), "id": "d3"}]),
        ])
        agent = build_agent(model, instruction="Only propose_decision may return a decision; never execute production.")
        config = session_config(1, 2, 3, 4)
        first = agent.invoke({"messages": [HumanMessage(content="你好")]}, config)
        output = [m for m in first["messages"] if isinstance(m, ToolMessage)][-1]
        self.assertEqual(decision, json.loads(output.content))
        self.assertEqual(1, model.index)  # return_direct avoids a second model charge
        self.assertEqual(["propose_decision"], model.bound_tools)
        second = agent.invoke({"messages": [HumanMessage(content="我想生成短剧")]}, config)
        self.assertEqual(["你好", "我想生成短剧"], [m.content for m in second["messages"] if isinstance(m, HumanMessage)])
        isolated = agent.invoke({"messages": [HumanMessage(content="另一租户")]}, session_config(2, 2, 3, 4))
        self.assertEqual(["另一租户"], [m.content for m in isolated["messages"] if isinstance(m, HumanMessage)])

    def test_unlisted_tools_cannot_execute_even_when_model_hallucinates(self):
        model = ReplayModel(responses=[AIMessage(content="", tool_calls=[{"name": "write_file", "args": {"file_path": "/do-not-write", "content": "x"}, "id": "bad"}])])
        agent = build_agent(model, instruction="Use only propose_decision")
        with self.assertRaisesRegex(ValueError, "capability contract"):
            agent.invoke({"messages": [HumanMessage(content="hello")]}, session_config(1, 2, 3, 4))

    def test_scope_is_mandatory(self):
        with self.assertRaises(ValueError):
            session_config(0, 2, 3, 4)

    def test_large_frozen_history_does_not_invoke_a_hidden_summary_model(self):
        model = ReplayModel(responses=[AIMessage(content="", tool_calls=[
            {"name": "propose_decision", "args": proposal(), "id": "only-call"}])])
        history = "history " * 110000
        agent = build_agent(model, instruction="Use the frozen context without summary calls")
        agent.invoke({"messages": [HumanMessage(content=history)]}, session_config(1, 2, 3, 4))
        self.assertEqual(1, model.index)
        self.assertEqual(history, model.calls[0][-1].content)


if __name__ == "__main__":
    unittest.main()
