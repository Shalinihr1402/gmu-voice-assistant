import { useNavigate } from 'react-router-dom'
import './Profile.css'

const Profile = () => {
  const navigate = useNavigate()

  return (
    <div className="profile-page">
      <header className="profile-header">
        <div className="header-left">
          <span className="logo-text">GMU-ERP</span>
        </div>
        <nav className="header-nav">
          <a href="#" onClick={(e) => { e.preventDefault(); navigate('/'); }}>Student Hallticket</a>
          <a href="#" onClick={(e) => { e.preventDefault(); navigate('/profile'); }} className="active">Profile</a>
          <a href="#" onClick={(e) => { e.preventDefault(); navigate('/registration'); }}>Student Registration</a>
          <a href="#" onClick={(e) => { e.preventDefault(); }}>Student Result</a>
          <a href="#" onClick={(e) => { e.preventDefault(); }}>Competency Certificate</a>
        </nav>
        <div className="header-user">
          <span>I M SHIVAKUMARA ▾</span>
        </div>
      </header>

      <main className="profile-main">
        <h1 className="profile-title">Profile</h1>
        
        <button className="change-password-btn">CHANGE PASSWORD</button>

        <div className="profile-form">
          <div className="form-field">
            <label>USER NAME</label>
            <input type="text" value="822991167838" readOnly className="readonly-input" />
          </div>

          <div className="form-field">
            <label>NAME</label>
            <input type="text" value="I M SHIVAKUMARA" readOnly className="readonly-input" />
          </div>

          <div className="form-field">
            <label>MOBILE NO</label>
            <input type="text" value="8105020220" />
          </div>

          <div className="form-field photo-field">
            <label>PHOTO</label>
            <div className="photo-upload-section">
              <div className="profile-photo-display">
                <div className="photo-placeholder">👤</div>
              </div>
              <div className="photo-info">
                <a href="#" className="photo-link">1000185162.jpg</a>
                <span className="photo-size">1.10 MB</span>
                <button className="delete-photo-btn">DELETE</button>
              </div>
            </div>
          </div>
        </div>

        <div className="profile-actions">
          <button className="reset-btn">RESET</button>
          <button className="save-btn">SAVE</button>
        </div>
      </main>
    </div>
  )
}

export default Profile
