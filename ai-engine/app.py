from flask import Flask, jsonify, request
from flask_cors import CORS

from rag_engine import RAGEngine

app = Flask(__name__)
CORS(app)

engine = RAGEngine()


@app.get("/")
def home():
    return jsonify({
        "status": "ok",
        "service": "gmu-rag-service"
    })


@app.get("/health")
def health():
    status = engine.health()
    return jsonify(status), 200 if status["ready"] else 503


@app.post("/rag/reindex")
def rag_reindex():
    payload = request.get_json(silent=True) or {}
    try:
        summary = engine.build_index(
            max_chunk_chars=int(payload.get("max_chunk_chars", 650)),
            chunk_overlap=int(payload.get("chunk_overlap", 120))
        )
        return jsonify({
            "ok": True,
            "summary": summary
        })
    except Exception as exc:
        return jsonify({
            "ok": False,
            "error": str(exc)
        }), 500


@app.post("/rag/retrieve")
def rag_retrieve():
    payload = request.get_json(silent=True) or {}
    query = (payload.get("query") or "").strip()
    role = (payload.get("role") or "student").strip().lower()
    top_k = int(payload.get("top_k", 4))

    if not query:
        return jsonify({
            "ok": False,
            "error": "query is required"
        }), 400

    try:
        items = engine.search(query=query, role=role, top_k=top_k)
        return jsonify({
            "ok": True,
            "items": items
        })
    except FileNotFoundError as exc:
        return jsonify({
            "ok": False,
            "error": str(exc),
            "hint": "Run /rag/reindex before retrieval."
        }), 503
    except Exception as exc:
        return jsonify({
            "ok": False,
            "error": str(exc)
        }), 500


@app.post("/chat")
def chat():
    payload = request.get_json(silent=True) or {}
    query = (payload.get("message") or "").strip()

    if not query:
        return jsonify({
            "reply": "Please ask a question."
        }), 400

    try:
        items = engine.search(query=query, role="all", top_k=1)
    except Exception:
        items = []

    if not items:
        return jsonify({
            "reply": "Sorry, I could not understand."
        })

    top_item = items[0]
    return jsonify({
        "reply": f"{top_item['topic']}: {top_item['content']}"
    })


if __name__ == "__main__":
    app.run(host="127.0.0.1", port=5000, debug=True)
