import { useState } from "react"
import { useNavigate } from "react-router-dom"
import "./Login.css"
import { fetchJson } from "../utils/api"

const Login = ({ setIsAuthenticated }) => {
  const [loginId, setLoginId] = useState("")
  const [password, setPassword] = useState("")
  const [error, setError] = useState("")
  const [loading, setLoading] = useState(false)
  const navigate = useNavigate()

  const handleLogin = async (e) => {
    e.preventDefault()

    if (!loginId || !password) {
      setError("Please fill all fields")
      return
    }

    setError("")
    setLoading(true)

    try {
      const data = await fetchJson("login.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json"
        },
        body: JSON.stringify({ loginId, password })
      })

      if (data.success) {
        setIsAuthenticated(true)
        navigate(data.role === "student" ? "/home" : "/portal")
      } else {
        setError(data.message || "Invalid credentials")
      }

    } catch (err) {
      console.error("Login error:", err)
      setError(err.message || "Server error. Please check backend.")
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="login-container">
      <div className="login-card">
        <h2>GM UNIVERSITY</h2>
        <p className="login-subtitle">University Voice Assistant Login</p>

        <form onSubmit={handleLogin}>
          <div className="input-group">
            <label>Login ID / Aadhaar</label>
            <input
              type="text"
              value={loginId}
              onChange={(e) => setLoginId(e.target.value)}
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
