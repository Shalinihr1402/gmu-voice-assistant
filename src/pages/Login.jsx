import { useState } from "react"
import { useNavigate } from "react-router-dom"
import "./Login.css"

const Login = () => {
  const [aadhaar, setAadhaar] = useState("")
  const [password, setPassword] = useState("")
  const [error, setError] = useState("")
  const navigate = useNavigate()

  const handleLogin = async (e) => {
    e.preventDefault()

    try {
      const res = await fetch("/api/gmu-voice-assistant/backend/login.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ aadhaar, password })
      })

      const data = await res.json()

      if (data.success) {
        navigate("/dashboard")
      } else {
        setError(data.message || "Invalid credentials")
      }

    } catch (err) {
      setError("Server error")
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
              required
            />
          </div>

          <div className="input-group">
            <label>Password</label>
            <input
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
            />
          </div>

          {error && <p className="error-text">{error}</p>}

          <button type="submit" className="login-btn">
            Login
          </button>
        </form>
      </div>

    </div>
  )
}

export default Login
