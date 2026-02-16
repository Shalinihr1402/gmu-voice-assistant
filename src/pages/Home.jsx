import { useNavigate } from 'react-router-dom'
import { useEffect, useState } from 'react'
import './Home.css'

const Home = () => {
  const navigate = useNavigate()
  const [student, setStudent] = useState(null)

  useEffect(() => {
    fetch("http://localhost:8080/gmu-voice-assistant/backend/getProfile.php", {
      credentials: "include"
    })
      .then(res => res.json())
      .then(data => {
        if (!data.error) {
          setStudent(data)
        } else {
          navigate("/") // not logged in → go to login
        }
      })
      .catch(err => {
        console.error(err)
        navigate("/")
      })
  }, [])

  if (!student) {
    return <div>Loading...</div>
  }

  return (
    <div className="home-page">
      <header className="home-header">
        <div className="header-left">
          <span className="logo-text">GMU-ERP</span>
        </div>

        <nav className="header-nav">
          <a href="#" onClick={(e) => { e.preventDefault(); navigate('/hallticket'); }}>
            Student Hallticket
          </a>

          <a href="#" onClick={(e) => { e.preventDefault(); navigate('/profile'); }}>
            Profile
          </a>

          <a href="#" onClick={(e) => { e.preventDefault(); navigate('/registration'); }}>
            Student Registration
          </a>

          <a href="#" onClick={(e) => e.preventDefault()}>
            Student Result
          </a>

          <a href="#" onClick={(e) => e.preventDefault()}>
            Competency Certificate
          </a>
        </nav>

        <div className="header-user">
          <span>👤 {student.full_name} ▾</span>
        </div>
      </header>

      <main className="home-main">
        <div className="buttons-container">

          <button className="menu-btn">
            HALL-TICKET
          </button>

          <button className="menu-btn" onClick={() => navigate('/profile')}>
            PROFILE
          </button>

          <button className="menu-btn" onClick={() => navigate('/registration')}>
            REGISTRATION
          </button>

          <button className="menu-btn">
            RESULT - SEE
          </button>

          <button className="menu-btn" onClick={() => navigate('/dashboard')}>
            STUDENT DASHBOARD
          </button>

          <button className="menu-btn">
            COMPETENCY CERTIFICATE
          </button>

          <button className="menu-btn">
            ASSESSMENT SCANS
          </button>

        </div>

        <div className="approvals-section">
          <div className="approvals-line"></div>
          <h2 className="approvals-heading">APPROVALS</h2>
        </div>
      </main>
    </div>
  )
}

export default Home
