import { useState } from "react"
import { useNavigate } from "react-router-dom"
import "./Login.css"

const Login = () => {
  const [aadhaar, setAadhaar] = useState("")
  const [password, setPassword] = useState("")
  const [error, setError] = useState("")
  const [loading, setLoading] = useState(false)
  const navigate = useNavigate()

  const handleLogin = async (e) => {
    e.preventDefault()

    // Prevent empty submit
    if (!aadhaar || !password) {
      setError("Please fill all fields")
      return
    }

    setError("")
    setLoading(true)

    try {
      const res = await fetch(
        "http://localhost:8080/gmu-voice-assistant/backend/login.php",
        {
          method: "POST",
          headers: {
            "Content-Type": "application/json"
          },
          credentials: "include",
          body: JSON.stringify({ aadhaar, password })
        }
      )

      const text = await res.text()   // 🔥 safer parsing

      console.log("Raw Response:", text)

      let data
      try {
        data = JSON.parse(text)
      } catch (err) {
        throw new Error("Invalid JSON from server")
      }

      if (data.success === true) {
        navigate("/home")
      } else {
        setError(data.message || "Invalid credentials")
      }

    } catch (err) {
      console.error("Login error:", err)
      setError("Server error. Please check backend.")
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="login-container">
      <div className="login-card">
        <h2>GM UNIVERSITY</h2>
        <p className="login-subtitle">Student ERP Login</p>

        <form onSubmit={handleLogin}>
          <div className="input-group">
            <label>Aadhaar Number</label>
            <input
              type="text"
              value={aadhaar}
              onChange={(e) => setAadhaar(e.target.value)}
            />
          </div>

          <div className="input-group">
            <label>Password</label>
            <input
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
            />
          </div>

          {error && <p className="error-text">{error}</p>}

          <button 
            type="submit" 
            className="login-btn"
            disabled={loading}
          >
            {loading ? "Logging in..." : "Login"}
          </button>
        </form>
      </div>
    </div>
  )
}

export default Login
