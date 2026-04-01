from rag_engine import RAGEngine


def main():
    engine = RAGEngine()
    summary = engine.build_index()
    print("RAG index built successfully.")
    print(summary)


if __name__ == "__main__":
    main()
