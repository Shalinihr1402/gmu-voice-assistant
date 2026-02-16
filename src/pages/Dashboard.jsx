import { useNavigate } from 'react-router-dom'
import { useEffect, useState } from 'react'
import './Dashboard.css'

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
        }
      })
      .catch(err => console.error(err))
  }, [])

  if (!student) {
    return <div>Loading...</div>
  }

  return (
    <div className="dashboard-page">
      <header className="dashboard-header">
        <div className="header-center">
          <h1>GM UNIVERSITY</h1>
        </div>
      </header>

      <div className="dashboard-container">
        <aside className="dashboard-sidebar">
          <div className="sidebar-profile">
            <div className="sidebar-photo">
              <div className="photo-placeholder">👤</div>
            </div>
            <h3 className="sidebar-name">{student.name}</h3>
          </div>

          <nav className="sidebar-menu">
            <div className="menu-item" onClick={() => navigate('/registration')}>
              Assignment Submit
            </div>
            <div className="menu-item">
              Fact Report
            </div>
          </nav>
        </aside>

        <main className="dashboard-main">
          <div className="dashboard-content">

            <div className="student-info-section">
              <h2 className="student-name">{student.name}</h2>

              <div className="student-details">
                <div className="detail-item">
                  <strong>USN :</strong> {student.usn}
                </div>

                <div className="detail-item">
                  <strong>Designation :</strong> Student
                </div>

                <div className="detail-item">
                  <strong>Discipline :</strong> {student.branch}
                </div>

                <div className="detail-item">
                  <strong>Semester :</strong> {student.semester}
                </div>

                <div className="detail-item">
                  <strong>School :</strong> {student.school}
                </div>

                <div className="detail-item">
                  <strong>Faculty :</strong> {student.faculty}
                </div>
              </div>
            </div>

          </div>
        </main>
      </div>
    </div>
  )
}

export default Dashboard
