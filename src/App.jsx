import { BrowserRouter as Router, Routes, Route, Navigate, useLocation } from "react-router-dom"
import { useEffect, useState } from "react"

import VoiceAssistant from "./components/VoiceAssistant"
import Login from "./pages/Login"
import Home from "./pages/Home"
import Registration from "./pages/Registration"
import Profile from "./pages/Profile"
import Dashboard from "./pages/Dashboard"

import "./App.css"

function AppContent({ isAuthenticated, setIsAuthenticated }) {
  const location = useLocation()

  return (
    <div className="app-container">

      <Routes>
        {/* Login */}
        <Route
          path="/"
          element={<Login setIsAuthenticated={setIsAuthenticated} />}
        />

        {/* Protected Dashboard */}
        <Route
          path="/dashboard"
          element={
            isAuthenticated
              ? <Dashboard />
              : <Navigate to="/" />
          }
        />

        <Route path="/home" element={<Home />} />
        <Route path="/registration" element={<Registration />} />
        <Route path="/profile" element={<Profile />} />
      </Routes>

      {/* 🔥 VoiceAssistant appears on ALL pages except login */}
      {location.pathname !== "/" && <VoiceAssistant />}

    </div>
  )
}

function App() {
  const [isAuthenticated, setIsAuthenticated] = useState(false)

  // Optional: keep backend check
  useEffect(() => {
    fetch("http://localhost:8080/gmu-voice-assistant/backend/checkAuth.php", {
      credentials: "include"
    })
      .then(res => res.json())
      .then(data => {
        if (data.loggedIn) {
          setIsAuthenticated(true)
        }
      })
      .catch(() => {})
  }, [])

  return (
    <Router>
      <AppContent
        isAuthenticated={isAuthenticated}
        setIsAuthenticated={setIsAuthenticated}
      />
    </Router>
  )
}

export default App
