import { useState } from "react"
import { useNavigate } from "react-router-dom"
import "./Login.css"

const Login = ({ setIsAuthenticated }) => {   // 🔥 receive prop
  const [aadhaar, setAadhaar] = useState("")
  const [password, setPassword] = useState("")
  const [error, setError] = useState("")
  const [loading, setLoading] = useState(false)
  const navigate = useNavigate()

  const handleLogin = async (e) => {
    e.preventDefault()

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

      const data = await res.json()

      console.log("Login response:", data)

      // ✅ Correct condition
      if (data.success) {
        setIsAuthenticated(true)   // 🔥 update auth state
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
