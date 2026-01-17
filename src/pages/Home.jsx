import { useNavigate } from 'react-router-dom'
import './Home.css'

const Home = () => {
  const navigate = useNavigate()

  return (
    <div className="home-page">
      <header className="home-header">
        <div className="header-left">
          <span className="logo-text">GMU-ERP</span>
        </div>
        <nav className="header-nav">
          <a href="#" onClick={(e) => { e.preventDefault(); navigate('/'); }}>Student Hallticket</a>
          <a href="#" onClick={(e) => { e.preventDefault(); navigate('/profile'); }}>Profile</a>
          <a href="#" onClick={(e) => { e.preventDefault(); navigate('/registration'); }}>Student Registration</a>
          <a href="#" onClick={(e) => { e.preventDefault(); }}>Student Result</a>
          <a href="#" onClick={(e) => { e.preventDefault(); }}>Competency Certificate</a>
        </nav>
        <div className="header-user">
          <span>IM SHIVAKUMARA ▾</span>
        </div>
      </header>

      <main className="home-main">
        <div className="buttons-container">
          <button className="menu-btn" onClick={() => navigate('/')}>
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
