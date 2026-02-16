import { BrowserRouter as Router, Routes, Route, Navigate } from "react-router-dom"
import { useEffect, useState } from "react"

import VoiceAssistant from "./components/VoiceAssistant"
import Login from "./pages/Login"
import Home from "./pages/Home"
import Registration from "./pages/Registration"
import Profile from "./pages/Profile"
import Dashboard from "./pages/Dashboard"

import "./App.css"

function App() {

  const [isAuthenticated, setIsAuthenticated] = useState(null)

  // 🔐 Check session from backend
  useEffect(() => {
    fetch("/api/gmu-voice-assistant/backend/checkAuth.php")
      .then(res => res.json())
      .then(data => {
        setIsAuthenticated(data.loggedIn)
      })
      .catch(() => {
        setIsAuthenticated(false)
      })
  }, [])

  if (isAuthenticated === null) {
    return <div>Loading...</div>
  }

  return (
    <Router>
      <div className="app-container">

        <Routes>

          {/* Login Page */}
          <Route path="/" element={<Login />} />

          {/* Protected Dashboard */}
          <Route
            path="/dashboard"
            element={
              isAuthenticated
                ? <Dashboard />
                : <Navigate to="/" />
            }
          />

          <Route path="/registration" element={<Registration />} />
          <Route path="/profile" element={<Profile />} />
          <Route path="/home" element={<Home />} />

        </Routes>

        {/* Voice assistant only after login */}
        {isAuthenticated && <VoiceAssistant />}

      </div>
    </Router>
  )
}

export default App
