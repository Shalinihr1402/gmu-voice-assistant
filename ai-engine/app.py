from flask import Flask, request, jsonify
from db import get_db
from flask_cors import CORS

app = Flask(__name__)

@app.route("/chat", methods=["POST"])
def chat():
    data = request.json
    message = data.get("message","").lower()

    db = get_db()
    cur = db.cursor(dictionary=True)

    reply = "Sorry, I could not understand."

 
    if "fee" in message:
        cur.execute("SELECT balance FROM fees LIMIT 1")
        r = cur.fetchone()
        reply = f"Your fee balance is {r['balance']} rupees."

 
    elif "subject" in message or "course" in message:
        cur.execute("SELECT name FROM courses WHERE branch='MCA' AND semester=3")
        subjects = [row["name"] for row in cur.fetchall()]
        reply = "Your subjects are: " + ", ".join(subjects)

   
    elif "attendance" in message:
        cur.execute("SELECT subject,percentage FROM attendance WHERE student_id=1")
        rows = cur.fetchall()
        reply = "Your attendance: " + ", ".join(
            [f"{r['subject']} {r['percentage']}%" for r in rows]
        )

 
    elif "result" in message or "grade" in message:
        cur.execute("SELECT subject,grade FROM results WHERE student_id=1")
        rows = cur.fetchall()
        reply = "Your results: " + ", ".join(
            [f"{r['subject']} grade {r['grade']}" for r in rows]
        )

   
    elif "notification" in message or "notice" in message:
        cur.execute("SELECT title,message FROM notifications ORDER BY id DESC LIMIT 2")
        rows = cur.fetchall()
        reply = "Latest notifications: " + " | ".join(
            [f"{r['title']}: {r['message']}" for r in rows]
        )

   
    else:
        cur.execute("SELECT answer FROM knowledge_base WHERE question LIKE %s LIMIT 1",(f"%{message}%",))
        row = cur.fetchone()
        if row:
            reply = row["answer"]
        else:
            cur.execute("INSERT INTO knowledge_base(role,question,answer) VALUES(%s,%s,%s)",
                        ("student",message,"Pending"))
            db.commit()
            reply = "I will learn this soon."

    return jsonify({"reply": reply})

app.run(port=5000, debug=True)
