import { useNavigate } from 'react-router-dom'
import { useEffect, useState } from 'react'
import './Profile.css'

const Profile = () => {
  const navigate = useNavigate()
  const [student, setStudent] = useState(null)
  const [mobile, setMobile] = useState("")

  useEffect(() => {
    fetch("http://localhost:8080/gmu-voice-assistant/backend/getProfile.php", {
      credentials: "include"
    })
      .then(res => res.json())
      .then(data => {
        if (!data.error) {
          setStudent(data)
          setMobile(data.mobile_no || "")
        } else {
          navigate("/") // redirect if not logged in
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
    <div className="profile-page">

      {/* HEADER */}
      <header className="profile-header">
        <div className="header-left">
          <span className="logo-text">GMU-ERP</span>
        </div>

        <nav className="header-nav">
          <a href="#" onClick={(e) => { e.preventDefault(); navigate('/home'); }}>
            Student Hallticket
          </a>

          <a href="#" onClick={(e) => { e.preventDefault(); navigate('/profile'); }}
             className="active">
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
          👤 {student.full_name} ▾
        </div>
      </header>

      {/* MAIN */}
      <main className="profile-main">

        <h1 className="profile-title">Profile</h1>

        <button className="change-password-btn">
          CHANGE PASSWORD
        </button>

        <div className="profile-form">

          <div className="form-field">
            <label>USER NAME</label>
            <input
              type="text"
              value={student.aadhaar_number}
              readOnly
              className="readonly-input"
            />
          </div>

          <div className="form-field">
            <label>NAME</label>
            <input
              type="text"
              value={student.full_name}
              readOnly
              className="readonly-input"
            />
          </div>

          <div className="form-field">
            <label>MOBILE NO</label>
            <input
              type="text"
              value={mobile}
              onChange={(e) => setMobile(e.target.value)}
            />
          </div>

          <div className="form-field">
            <label>EMAIL</label>
            <input
              type="text"
              value={student.email}
              readOnly
              className="readonly-input"
            />
          </div>

        </div>

        <div className="profile-actions">
          <button
            className="reset-btn"
            onClick={() => setMobile(student.mobile_no)}
          >
            RESET
          </button>

          <button className="save-btn">
            SAVE
          </button>
        </div>

      </main>
    </div>
  )
}

export default Profile
