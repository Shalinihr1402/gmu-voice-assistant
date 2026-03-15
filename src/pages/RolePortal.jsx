import { useEffect, useState } from "react"
import { Navigate } from "react-router-dom"
import { fetchJson } from "../utils/api"

const roleDescriptions = {
  teacher: "Teacher access is active. This portal can be extended for attendance, courses, and academic support tasks.",
  hod: "HOD access is active. This portal is ready for department-level monitoring and approvals.",
  director: "Director access is active. This portal can be extended for university-wide reporting and escalations.",
  dean: "Dean access is active. This portal can be extended for academic planning and oversight.",
  registrar: "Registrar access is active. This portal can be extended for records, compliance, and verification tasks.",
  management: "Management access is active. This portal can be extended for strategic summaries and decision support."
}

const RolePortal = () => {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    fetchJson("getCurrentUser.php")
      .then((data) => {
        setUser(data)
        setLoading(false)
      })
      .catch(() => {
        setLoading(false)
      })
  }, [])

  if (loading) {
    return <div>Loading...</div>
  }

  if (!user || user.error) {
    return <Navigate to="/" />
  }

  if (user.role_key === "student") {
    return <Navigate to="/home" />
  }

  return (
    <div className="home-page" style={{ padding: "40px" }}>
      <div className="login-card" style={{ maxWidth: "720px", margin: "0 auto" }}>
        <h2>{user.role_name} Portal</h2>
        <p className="login-subtitle">{roleDescriptions[user.role_key]}</p>

        <div className="profile-form">
          <div className="form-field">
            <label>Name</label>
            <input type="text" value={user.full_name || ""} readOnly />
          </div>
          <div className="form-field">
            <label>Login ID</label>
            <input type="text" value={user.login_id || ""} readOnly />
          </div>
          <div className="form-field">
            <label>Email</label>
            <input type="text" value={user.email || ""} readOnly />
          </div>
          <div className="form-field">
            <label>Department / Unit</label>
            <input type="text" value={user.unit_name || ""} readOnly />
          </div>
          <div className="form-field">
            <label>Designation</label>
            <input type="text" value={user.designation || user.role_name || ""} readOnly />
          </div>
        </div>
      </div>
    </div>
  )
}

export default RolePortal
