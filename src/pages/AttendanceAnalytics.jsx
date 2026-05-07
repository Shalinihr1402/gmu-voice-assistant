import { useEffect, useMemo, useState } from "react"
import { useNavigate } from "react-router-dom"

import { fetchJson } from "../utils/api"
import "./AttendanceAnalytics.css"

const AttendanceAnalytics = () => {
  const navigate = useNavigate()
  const [data, setData] = useState(null)
  const [errorMessage, setErrorMessage] = useState("")
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    fetchJson("getAttendanceAnalytics.php")
      .then((response) => {
        setData(response)
        setLoading(false)
      })
      .catch((error) => {
        setErrorMessage(error.message || "Unable to load attendance analytics right now.")
        setLoading(false)
      })
  }, [])

  const graphItems = useMemo(() => {
    const subjects = Array.isArray(data?.subjects) ? data.subjects : []
    return subjects.map((subject) => ({
      ...subject,
      shortLabel: subject.course_code || subject.course_title
    }))
  }, [data])

  if (loading) {
    return <div className="attendance-analytics-loading">Loading attendance analytics...</div>
  }

  if (errorMessage) {
    return (
      <div className="attendance-analytics-loading">
        <div className="attendance-error-card">
          <p>{errorMessage}</p>
          <button type="button" onClick={() => navigate("/home")}>Go back</button>
        </div>
      </div>
    )
  }

  if (!data) {
    return null
  }

  const { student, summary } = data

  return (
    <div className="attendance-page">
      <header className="attendance-header">
        <div className="logo-text">GMU-ERP</div>

        <nav className="header-nav">
          <a href="#" onClick={(event) => { event.preventDefault(); navigate("/home") }}>
            Student Hallticket
          </a>
          <a href="#" onClick={(event) => { event.preventDefault(); navigate("/profile") }}>
            Profile
          </a>
          <a href="#" onClick={(event) => { event.preventDefault(); navigate("/registration") }}>
            Student Registration
          </a>
          <a href="#" className="active" onClick={(event) => event.preventDefault()}>
            Attendance Analytics
          </a>
          <a href="#" onClick={(event) => { event.preventDefault(); navigate("/results") }}>
            Student Result
          </a>
        </nav>

        <div className="header-user">
          {student.full_name}
        </div>
      </header>

      <main className="attendance-main">
        <section className="attendance-hero">
          <div>
            <p className="attendance-kicker">Current Semester</p>
            <h1>Subject-wise Attendance Graph</h1>
            <p className="attendance-subtitle">
              View your current semester attendance statistics in a simple visual graph.
            </p>
          </div>
          <div className="attendance-student-meta">
            <span>{student.usn}</span>
            <span>{student.branch}</span>
            <span>Semester {student.semester}</span>
          </div>
        </section>

        <section className="attendance-summary-grid">
          <div className="attendance-summary-card">
            <span>Overall Attendance</span>
            <strong>{summary.overall_percentage}%</strong>
          </div>
          <div className="attendance-summary-card">
            <span>Attended Classes</span>
            <strong>{summary.attended_classes} / {summary.total_classes}</strong>
          </div>
          <div className="attendance-summary-card">
            <span>Subjects Tracked</span>
            <strong>{summary.subject_count}</strong>
          </div>
          <div className={`attendance-summary-card ${summary.below_threshold_count > 0 ? "warning" : "safe"}`}>
            <span>Below 75%</span>
            <strong>{summary.below_threshold_count}</strong>
          </div>
        </section>

        <section className="attendance-chart-card">
          <div className="attendance-chart-header">
            <div>
              <h2>Attendance by Subject</h2>
              <p>Bars show the percentage for each subject in your current semester.</p>
            </div>
          </div>

          <div className="attendance-chart">
            {graphItems.map((subject) => (
              <div key={subject.course_code} className="attendance-bar-row">
                <div className="attendance-bar-labels">
                  <strong>{subject.shortLabel}</strong>
                  <span>{subject.course_title}</span>
                </div>
                <div className="attendance-bar-track">
                  <div
                    className={`attendance-bar-fill ${subject.percentage < 75 ? "low" : "safe"}`}
                    style={{ width: `${Math.max(subject.percentage, 6)}%` }}
                  >
                    <span>{subject.percentage}%</span>
                  </div>
                  <div className="attendance-threshold-marker" />
                </div>
                <div className="attendance-bar-meta">
                  <span>{subject.attended_classes}/{subject.total_classes}</span>
                  <span>{subject.status}</span>
                </div>
              </div>
            ))}
          </div>
        </section>

        <section className="attendance-table-card">
          <h2>Detailed Attendance</h2>
          <div className="attendance-table-wrap">
            <table className="attendance-table">
              <thead>
                <tr>
                  <th>Course Code</th>
                  <th>Course Title</th>
                  <th>Type</th>
                  <th>Attended</th>
                  <th>Total</th>
                  <th>Percentage</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {graphItems.map((subject) => (
                  <tr key={`${subject.course_code}-table`}>
                    <td>{subject.course_code}</td>
                    <td>{subject.course_title}</td>
                    <td>{subject.course_type}</td>
                    <td>{subject.attended_classes}</td>
                    <td>{subject.total_classes}</td>
                    <td>{subject.percentage}%</td>
                    <td className={subject.percentage < 75 ? "low-status" : "safe-status"}>
                      {subject.status}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      </main>
    </div>
  )
}

export default AttendanceAnalytics
