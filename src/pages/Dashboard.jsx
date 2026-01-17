import { useNavigate } from 'react-router-dom'
import './Dashboard.css'

const Dashboard = () => {
  const navigate = useNavigate()

  return (
    <div className="dashboard-page">
      <header className="dashboard-header">
        <div className="header-center">
          <h1>GM UNIVERSITY</h1>
        </div>
        <div className="header-logo">
          <div className="logo-circle">
            <div className="logo-content">
              <div className="logo-icon">🌳</div>
              <div className="logo-text-small">GM UNIVERSITY</div>
              <div className="logo-tagline">Denovating Minds</div>
            </div>
          </div>
        </div>
      </header>

      <div className="dashboard-container">
        <aside className="dashboard-sidebar">
          <div className="sidebar-profile">
            <div className="sidebar-photo">
              <div className="photo-placeholder">👤</div>
            </div>
            <h3 className="sidebar-name">I M SHIVAKUMARA</h3>
          </div>

          <nav className="sidebar-menu">
            <div className="menu-item" onClick={() => navigate('/registration')}>
              Assignmnet Submit
            </div>
            <div className="menu-item">
              Fact Report
            </div>
          </nav>
        </aside>

        <main className="dashboard-main">
          <div className="dashboard-content">
            <div className="university-image-section">
              <div className="university-image">
                <div className="image-overlay"></div>
              </div>
            </div>

            <div className="student-info-section">
              <h2 className="student-name">I M SHIVAKUMARA</h2>
              
              <div className="student-details">
                <div className="detail-item">
                  <strong>USN :</strong> P24C01CA026
                </div>
                <div className="detail-item">
                  <strong>Designation :</strong> Student
                </div>
                <div className="detail-item">
                  <strong>Section :</strong> NA
                </div>
                <div className="detail-item">
                  <strong>Discipline :</strong> MCA
                </div>
                <div className="detail-item">
                  <strong>School :</strong> SCA
                </div>
                <div className="detail-item">
                  <strong>Faculty :</strong> FCIT
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
