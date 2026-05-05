import { useEffect, useMemo, useState } from "react"
import { useNavigate } from "react-router-dom"

import { fetchJson } from "../utils/api"
import "./Result.css"

const EXAM_OPTIONS = ["SEE", "RESIT", "RE-REGISTRATION"]
const SEASON_OPTIONS = ["ODD", "EVEN"]

const buildAcademicYears = () => {
  const currentYear = new Date().getFullYear()
  const years = []

  for (let start = currentYear - 5; start <= currentYear; start += 1) {
    years.push(`${start}-${String(start + 1).slice(-2)}`)
  }

  return years.reverse()
}

const Result = () => {
  const navigate = useNavigate()
  const [student, setStudent] = useState(null)
  const [resultData, setResultData] = useState(null)
  const [errorMessage, setErrorMessage] = useState("")
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [form, setForm] = useState({
    usn: "",
    semester: "",
    exam: "",
    year: "",
    season: ""
  })

  const academicYearOptions = useMemo(() => buildAcademicYears(), [])
  const semesterOptions = useMemo(() => {
    const currentSemester = Number(student?.semester || 0)
    if (!currentSemester) {
      return []
    }

    return Array.from({ length: currentSemester }, (_, index) => String(index + 1))
  }, [student?.semester])

  useEffect(() => {
    fetchJson("getProfile.php")
      .then((data) => {
        if (data.error) {
          navigate("/")
          return
        }

        setStudent(data)
        setForm((current) => ({
          ...current,
          usn: data.usn || "",
          semester: data.semester ? String(data.semester) : "",
          year: academicYearOptions[0] || ""
        }))
        setLoading(false)
      })
      .catch(() => navigate("/"))
  }, [academicYearOptions, navigate])

  const handleChange = (field, value) => {
    setForm((current) => ({
      ...current,
      [field]: value
    }))
  }

  const handleSubmit = async (event) => {
    event.preventDefault()
    setSubmitting(true)
    setErrorMessage("")
    setResultData(null)

    try {
      const data = await fetchJson("getSemesterResult.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(form)
      })

      setResultData(data)
    } catch (error) {
      setErrorMessage(error.message || "Unable to fetch result right now.")
    } finally {
      setSubmitting(false)
    }
  }

  const handleDownload = () => {
    window.print()
  }

  if (loading || !student) {
    return <div className="result-loading">Loading result page...</div>
  }

  return (
    <div className="result-page">
      <header className="result-header">
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
            Student Result
          </a>
          <a href="#" onClick={(event) => { event.preventDefault(); navigate("/certificate") }}>
            Digital Competency Certificate
          </a>
        </nav>

        <div className="header-user">
          👤 {student.full_name} ▾
        </div>
      </header>

      <main className="result-main">
        <section className="result-form-shell">
          <h1>GM University Grade Sheet Generator</h1>

          <form className="result-form-card" onSubmit={handleSubmit}>
            <label>
              <span>Enter USN</span>
              <input
                type="text"
                value={form.usn}
                onChange={(event) => handleChange("usn", event.target.value.toUpperCase())}
                placeholder="Enter USN"
              />
            </label>

            <label>
              <span>Semester</span>
              <select
                value={form.semester}
                onChange={(event) => handleChange("semester", event.target.value)}
              >
                <option value="">Select Semester</option>
                {semesterOptions.map((semester) => (
                  <option key={semester} value={semester}>{semester}</option>
                ))}
              </select>
            </label>

            <label>
              <span>Exam</span>
              <select
                value={form.exam}
                onChange={(event) => handleChange("exam", event.target.value)}
              >
                <option value="">Select Exam</option>
                {EXAM_OPTIONS.map((exam) => (
                  <option key={exam} value={exam}>{exam}</option>
                ))}
              </select>
            </label>

            <label>
              <span>Exam Conducted Year</span>
              <select
                value={form.year}
                onChange={(event) => handleChange("year", event.target.value)}
              >
                <option value="">Select Academic Year</option>
                {academicYearOptions.map((year) => (
                  <option key={year} value={year}>{year}</option>
                ))}
              </select>
            </label>

            <label>
              <span>Exam Conducted Season</span>
              <select
                value={form.season}
                onChange={(event) => handleChange("season", event.target.value)}
              >
                <option value="">Select Season</option>
                {SEASON_OPTIONS.map((season) => (
                  <option key={season} value={season}>{season}</option>
                ))}
              </select>
            </label>

            {errorMessage && <p className="result-error">{errorMessage}</p>}

            <div className="result-form-actions">
              <button type="submit" disabled={submitting}>
                {submitting ? "Loading..." : "Submit"}
              </button>
            </div>
          </form>
        </section>

        {resultData && (
          <section className="grade-sheet-card" id="grade-sheet-card">
            <div className="grade-sheet-top">
              <div>
                <p className="sheet-kicker">Semester Result</p>
                <h2>{resultData.student.full_name}</h2>
              </div>
              <button type="button" onClick={handleDownload}>
                Download Result
              </button>
            </div>

            <div className="grade-sheet-grid">
              <div><span>USN</span><strong>{resultData.student.usn}</strong></div>
              <div><span>Branch</span><strong>{resultData.student.branch}</strong></div>
              <div><span>Semester</span><strong>{resultData.selection.semester}</strong></div>
              <div><span>Exam</span><strong>{resultData.selection.exam}</strong></div>
              <div><span>Academic Year</span><strong>{resultData.selection.year}</strong></div>
              <div><span>Season</span><strong>{resultData.selection.season}</strong></div>
            </div>

            <div className="grade-summary-row">
              <div className="summary-box">
                <span>SGPA</span>
                <strong>{resultData.summary.sgpa}</strong>
              </div>
              <div className="summary-box">
                <span>Total Credits</span>
                <strong>{resultData.summary.credits}</strong>
              </div>
              <div className={`summary-box ${resultData.summary.status === "PASS" ? "pass" : "fail"}`}>
                <span>Status</span>
                <strong>{resultData.summary.status}</strong>
              </div>
            </div>

            <div className="backlog-note">
              {resultData.summary.backlog_count > 0
                ? `Backlogs: ${resultData.summary.backlogs.join(", ")}`
                : "No backlog in this semester."}
            </div>

            <div className="grade-table-wrap">
              <table className="grade-table">
                <thead>
                  <tr>
                    <th>Course Code</th>
                    <th>Course Title</th>
                    <th>Credits</th>
                    <th>Grade Point</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {resultData.subjects.map((subject) => (
                    <tr key={`${subject.course_code}-${subject.course_title}`}>
                      <td>{subject.course_code}</td>
                      <td>{subject.course_title}</td>
                      <td>{subject.credits}</td>
                      <td>{subject.grade_point}</td>
                      <td className={subject.status === "PASS" ? "pass-cell" : "fail-cell"}>
                        {subject.status}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        )}
      </main>
    </div>
  )
}

export default Result
