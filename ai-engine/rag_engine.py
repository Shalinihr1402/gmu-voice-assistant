import json
import math
import os
import re
import urllib.error
import urllib.request
from pathlib import Path

from db import get_db

try:
    import faiss  # type: ignore
except ImportError:  # pragma: no cover - optional dependency
    faiss = None

try:
    import numpy as np  # type: ignore
except ImportError:  # pragma: no cover - optional dependency
    np = None


class RAGEngine:
    def __init__(self):
        self.base_dir = Path(__file__).resolve().parent
        self.data_dir = self.base_dir / "data" / "rag"
        self.data_dir.mkdir(parents=True, exist_ok=True)
        self.metadata_path = self.data_dir / "metadata.json"
        self.index_path = self.data_dir / "index.faiss"
        self.vector_path = self.data_dir / "vectors.json"
        self.embedding_model = os.getenv("GEMINI_EMBEDDING_MODEL", "models/text-embedding-004")
        self.api_key = (
            os.getenv("GEMINI_API_KEY")
            or os.getenv("GOOGLE_API_KEY")
            or ""
        ).strip()

    def health(self):
        return {
            "ready": self.metadata_path.exists(),
            "faiss_enabled": faiss is not None,
            "embedding_model": self.embedding_model,
            "document_count": len(self._load_metadata()) if self.metadata_path.exists() else 0
        }

    def build_index(self, max_chunk_chars=650, chunk_overlap=120):
        documents = self._load_documents()
        chunks = self._chunk_documents(
            documents,
            max_chunk_chars=max(250, max_chunk_chars),
            chunk_overlap=max(0, min(chunk_overlap, max_chunk_chars // 2))
        )

        if not chunks:
            raise RuntimeError("No knowledge documents found to index.")

        embeddings = []
        for chunk in chunks:
            vector = self._embed_text(
                chunk["chunk_text"],
                task_type="RETRIEVAL_DOCUMENT",
                title=chunk["topic"]
            )
            chunk["embedding_dimension"] = len(vector)
            embeddings.append(self._normalize_vector(vector))

        self._save_index(chunks, embeddings)

        return {
            "documents": len(documents),
            "chunks": len(chunks),
            "embedding_dimension": len(embeddings[0]) if embeddings else 0,
            "faiss_enabled": faiss is not None
        }

    def search(self, query, role="student", top_k=4):
        role = (role or "student").strip().lower()
        top_k = max(1, top_k)
        metadata = self._load_metadata()
        vectors = self._load_vectors(metadata)

        if not metadata or not vectors:
            raise FileNotFoundError("RAG index is missing.")

        query_vector = self._normalize_vector(
            self._embed_text(query, task_type="RETRIEVAL_QUERY")
        )

        candidate_indexes = self._rank_candidates(query_vector, vectors, max(top_k * 5, 12))

        items = []
        for index in candidate_indexes:
            item = metadata[index]
            audience_role = (item.get("audience_role") or "all").strip().lower()
            if audience_role not in {"all", role}:
                continue

            similarity = self._cosine_similarity(query_vector, vectors[index])
            enriched = {
                "topic": item["topic"],
                "content": item["chunk_text"],
                "source": item["source"],
                "audience_role": audience_role,
                "score": round(similarity, 4)
            }
            items.append(enriched)

            if len(items) >= top_k:
                break

        return items

    def _load_documents(self):
        db = get_db()
        cur = db.cursor(dictionary=True)
        cur.execute(
            """
            SELECT kb_id, audience_role, topic, content
            FROM knowledge_base
            ORDER BY kb_id ASC
            """
        )
        rows = cur.fetchall()
        cur.close()
        db.close()
        return rows

    def _chunk_documents(self, documents, max_chunk_chars, chunk_overlap):
        chunks = []
        for document in documents:
            topic = self._clean_text(document.get("topic", ""))
            content = self._clean_text(document.get("content", ""))
            if not content:
                continue

            content_parts = self._split_sentences(content)
            current = []
            current_length = 0
            chunk_index = 0

            for sentence in content_parts:
                sentence_length = len(sentence)
                if current and current_length + sentence_length + 1 > max_chunk_chars:
                    chunk_text = " ".join(current).strip()
                    if chunk_text:
                        chunks.append(self._build_chunk(document, topic, chunk_text, chunk_index))
                        chunk_index += 1

                    overlap_text = chunk_text[-chunk_overlap:].strip() if chunk_overlap else ""
                    current = [overlap_text] if overlap_text else []
                    current_length = len(overlap_text)

                current.append(sentence)
                current_length += sentence_length + 1

            if current:
                chunk_text = " ".join(current).strip()
                if chunk_text:
                    chunks.append(self._build_chunk(document, topic, chunk_text, chunk_index))

        return chunks

    def _build_chunk(self, document, topic, chunk_text, chunk_index):
        source = f"knowledge_base:{document['kb_id']}#{chunk_index}"
        return {
            "id": source,
            "kb_id": int(document["kb_id"]),
            "topic": topic,
            "chunk_text": chunk_text,
            "audience_role": (document.get("audience_role") or "all").strip().lower(),
            "source": source
        }

    def _split_sentences(self, text):
        parts = re.split(r"(?<=[.!?])\s+|\n+", text)
        return [self._clean_text(part) for part in parts if self._clean_text(part)]

    def _clean_text(self, text):
        return re.sub(r"\s+", " ", str(text or "")).strip()

    def _embed_text(self, text, task_type, title=None):
        if not self.api_key:
            raise RuntimeError("Missing GEMINI_API_KEY or GOOGLE_API_KEY for embeddings.")

        payload = {
            "model": self.embedding_model,
            "content": {
                "parts": [{
                    "text": text
                }]
            },
            "taskType": task_type
        }

        if title:
            payload["title"] = title

        request_body = json.dumps(payload).encode("utf-8")
        url = (
            f"https://generativelanguage.googleapis.com/v1beta/"
            f"{self.embedding_model}:embedContent?key={self.api_key}"
        )

        req = urllib.request.Request(
            url,
            data=request_body,
            headers={"Content-Type": "application/json"},
            method="POST"
        )

        try:
            with urllib.request.urlopen(req, timeout=30) as response:
                data = json.loads(response.read().decode("utf-8"))
        except urllib.error.HTTPError as exc:
            detail = exc.read().decode("utf-8", errors="ignore")
            raise RuntimeError(f"Gemini embedding request failed: {detail}") from exc
        except urllib.error.URLError as exc:
            raise RuntimeError(f"Unable to reach Gemini embeddings API: {exc}") from exc

        values = ((data.get("embedding") or {}).get("values")) or []
        if not values:
            raise RuntimeError("Gemini embeddings API returned an empty vector.")

        return [float(value) for value in values]

    def _normalize_vector(self, vector):
        norm = math.sqrt(sum(value * value for value in vector))
        if norm == 0:
            return vector
        return [value / norm for value in vector]

    def _cosine_similarity(self, left, right):
        return sum(l * r for l, r in zip(left, right))

    def _save_index(self, metadata, vectors):
        with self.metadata_path.open("w", encoding="utf-8") as handle:
            json.dump(metadata, handle, ensure_ascii=True, indent=2)

        if faiss is not None and np is not None:
            dimension = len(vectors[0]) if vectors else 0
            index = faiss.IndexFlatIP(dimension)
            matrix = self._vectors_to_float32(vectors)
            index.add(matrix)
            faiss.write_index(index, str(self.index_path))
            if self.vector_path.exists():
                self.vector_path.unlink()
            return

        with self.vector_path.open("w", encoding="utf-8") as handle:
            json.dump(vectors, handle, ensure_ascii=True)

    def _load_metadata(self):
        with self.metadata_path.open("r", encoding="utf-8") as handle:
            return json.load(handle)

    def _load_vectors(self, metadata):
        if faiss is not None and np is not None and self.index_path.exists():
            index = faiss.read_index(str(self.index_path))
            vectors = []
            for row_index in range(index.ntotal):
                vectors.append(index.reconstruct(row_index).tolist())
            return vectors

        if self.vector_path.exists():
            with self.vector_path.open("r", encoding="utf-8") as handle:
                return json.load(handle)

        raise FileNotFoundError("RAG vector storage is missing.")

    def _rank_candidates(self, query_vector, vectors, limit):
        if faiss is not None and np is not None and self.index_path.exists():
            index = faiss.read_index(str(self.index_path))
            distances, indices = index.search(
                self._vectors_to_float32([query_vector]),
                min(limit, len(vectors))
            )
            return [int(value) for value in indices[0] if value >= 0]

        scored = [
            (self._cosine_similarity(query_vector, vector), idx)
            for idx, vector in enumerate(vectors)
        ]
        scored.sort(reverse=True)
        return [idx for _, idx in scored[:limit]]

    def _vectors_to_float32(self, vectors):
        if np is None:
            raise RuntimeError("NumPy is required when using FAISS.")
        return np.array(vectors, dtype="float32")
