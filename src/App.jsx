import { BrowserRouter as Router, Routes, Route, Navigate, useLocation } from "react-router-dom"
import { useEffect, useState } from "react"

import VoiceAssistant from "./components/VoiceAssistant"
import Login from "./pages/Login"
import Home from "./pages/Home"
import Registration from "./pages/Registration"
import Profile from "./pages/Profile"
import Dashboard from "./pages/Dashboard"
import RolePortal from "./pages/RolePortal"

import "./App.css"
import { fetchJson } from "./utils/api"

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
        <Route
          path="/portal"
          element={
            isAuthenticated
              ? <RolePortal />
              : <Navigate to="/" />
          }
        />
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
    fetchJson("checkAuth.php")
      .then(data => {
        if (data.loggedIn) {
          setIsAuthenticated(true)
        }
      })
      .catch(() => {})
  }, [])

  return (
    <Router future={{ v7_relativeSplatPath: true, v7_startTransition: true }}>
      <AppContent
        isAuthenticated={isAuthenticated}
        setIsAuthenticated={setIsAuthenticated}
      />
    </Router>
  )
}

export default App
