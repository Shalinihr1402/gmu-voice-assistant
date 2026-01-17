import { BrowserRouter as Router, Routes, Route } from 'react-router-dom'
import VoiceAssistant from './components/VoiceAssistant'
import Home from './pages/Home'
import Registration from './pages/Registration'
import Profile from './pages/Profile'
import Dashboard from './pages/Dashboard'
import './App.css'

function App() {
  return (
    <Router
      future={{
        v7_startTransition: true,
        v7_relativeSplatPath: true
      }}
    >
      <div className="app-container">
        <Routes>
          <Route path="/" element={<Home />} />
          <Route path="/registration" element={<Registration />} />
          <Route path="/profile" element={<Profile />} />
          <Route path="/dashboard" element={<Dashboard />} />
        </Routes>
        <VoiceAssistant />
      </div>
    </Router>
  )
}

export default App
