import { useNavigate } from 'react-router-dom'
import { useEffect, useState } from 'react'
import './Dashboard.css'
import collegeImage from '../assets/college.jpg'
import logo from '../assets/logo.png'

const Dashboard = () => {
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
          navigate("/")
        }
      })
      .catch(err => console.error(err))
  }, [navigate])

  if (!student) return <div>Loading...</div>

  return (
    <div className="dashboard-page">

      {/* HEADER */}
      <header className="dashboard-header">
        <h1>GM UNIVERSITY</h1>
        <img src={logo} alt="logo" className="header-logo" />
      </header>

      <div className="dashboard-layout">

        {/* SIDEBAR */}
        <aside className="dashboard-sidebar">
          <div className="sidebar-profile">
            <div className="sidebar-photo">
              {student.photo ? (
                <img
                  src={`http://localhost:8080/gmu-voice-assistant/backend/uploads/${student.photo}`}
                  alt="profile"
                />
              ) : (
                <div className="photo-placeholder">👤</div>
              )}
            </div>

            <h3>{student.full_name}</h3>
          </div>

          <div
            className="menu-item"
            onClick={() => navigate('/registration')}
          >
            Assignment Submit
          </div>

          <div className="menu-item">
            Fact Report
          </div>
        </aside>

        {/* MAIN CONTENT */}
        <main className="dashboard-main">

          <div className="main-card">

            {/* Banner */}
            <div className="banner-wrapper">
              <img src={collegeImage} alt="college" className="banner-img" />

              <div className="center-profile">
                {student.photo ? (
                  <img
                    src={`http://localhost:8080/gmu-voice-assistant/backend/uploads/${student.photo}`}
                    alt="profile"
                  />
                ) : (
                  <div className="photo-placeholder-large">👤</div>
                )}
              </div>
            </div>

            {/* Student Info */}
            <div className="student-info-section">
              <h2>{student.full_name}</h2>

              <div className="student-details">
                <p><strong>USN :</strong> {student.usn}</p>
                <p><strong>Designation :</strong> Student</p>
                <p><strong>Discipline :</strong> {student.branch}</p>
                <p><strong>Email :</strong> {student.email}</p>
              </div>
            </div>

          </div>

        </main>

      </div>
    </div>
  )
}

export default Dashboard
