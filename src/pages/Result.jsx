import { useEffect, useMemo, useRef, useState } from "react"
import { useLocation, useNavigate } from "react-router-dom"

import { fetchJson } from "../utils/api"
import "./Result.css"

const uniqueValues = (values) => Array.from(new Set(values.filter(Boolean)))
const sortSemesters = (values) => [...values].sort((left, right) => Number(left) - Number(right))
const sortAcademicYears = (values) => [...values].sort((left, right) => right.localeCompare(left))

const Result = () => {
  const navigate = useNavigate()
  const location = useLocation()
  const [student, setStudent] = useState(null)
  const [availableSelections, setAvailableSelections] = useState([])
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
  const voicePrefillSubmittedRef = useRef(false)

  useEffect(() => {
    voicePrefillSubmittedRef.current = false
  }, [location.search])

  useEffect(() => {
    const params = new URLSearchParams(location.search)
    const voicePrefill = {
      usn: (params.get("usn") || "").toUpperCase(),
      semester: params.get("semester") || "",
      exam: params.get("exam") || "",
      year: params.get("year") || "",
      season: params.get("season") || ""
    }

    Promise.all([
      fetchJson("getProfile.php"),
      fetchJson("getResultAvailability.php")
    ])
      .then(([profileData, availabilityData]) => {
        if (profileData.error) {
          navigate("/")
          return
        }

        setStudent(profileData)
        setAvailableSelections(Array.isArray(availabilityData?.selections) ? availabilityData.selections : [])
        setForm((current) => ({
          ...current,
          usn: voicePrefill.usn || profileData.usn || "",
          semester: voicePrefill.semester || current.semester || "",
          exam: voicePrefill.exam || current.exam,
          year: voicePrefill.year || current.year,
          season: voicePrefill.season || current.season
        }))
        setLoading(false)
      })
      .catch(() => navigate("/"))
  }, [location.search, navigate])

  const semesterOptions = useMemo(() => sortSemesters(uniqueValues(
    availableSelections.map((selection) => String(selection.semester || ""))
  )), [availableSelections])

  const examOptions = useMemo(() => uniqueValues(
    availableSelections
      .filter((selection) => !form.semester || String(selection.semester) === String(form.semester))
      .map((selection) => selection.exam)
  ), [availableSelections, form.semester])

  const academicYearOptions = useMemo(() => sortAcademicYears(uniqueValues(
    availableSelections
      .filter((selection) => {
        if (form.semester && String(selection.semester) !== String(form.semester)) return false
        if (form.exam && selection.exam !== form.exam) return false
        return true
      })
      .map((selection) => selection.year)
  )), [availableSelections, form.exam, form.semester])

  const seasonOptions = useMemo(() => uniqueValues(
    availableSelections
      .filter((selection) => {
        if (form.semester && String(selection.semester) !== String(form.semester)) return false
        if (form.exam && selection.exam !== form.exam) return false
        if (form.year && selection.year !== form.year) return false
        return true
      })
      .map((selection) => selection.season)
  ), [availableSelections, form.exam, form.semester, form.year])

  const handleChange = (field, value) => {
    setForm((current) => {
      if (field === "semester") {
        return {
          ...current,
          semester: value,
          exam: "",
          year: "",
          season: ""
        }
      }

      if (field === "exam") {
        return {
          ...current,
          exam: value,
          year: "",
          season: ""
        }
      }

      if (field === "year") {
        return {
          ...current,
          year: value,
          season: ""
        }
      }

      return {
        ...current,
        [field]: value
      }
    })
  }

  useEffect(() => {
    const params = new URLSearchParams(location.search)
    const hasVoicePrefill = ["usn", "semester", "exam", "year", "season"].every((key) => params.get(key))

    if (!hasVoicePrefill || !student || submitting || voicePrefillSubmittedRef.current) {
      return
    }

    if (!form.usn || !form.semester || !form.exam || !form.year || !form.season) {
      return
    }

    voicePrefillSubmittedRef.current = true
    setSubmitting(true)
    setErrorMessage("")
    setResultData(null)

    fetchJson("getSemesterResult.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(form)
    })
      .then((data) => {
        setResultData(data)
      })
      .catch((error) => {
        setErrorMessage(error.message || "Unable to fetch result right now.")
      })
      .finally(() => {
        setSubmitting(false)
      })
  }, [form, location.search, student, submitting])

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
                {examOptions.map((exam) => (
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
                {seasonOptions.map((season) => (
                  <option key={season} value={season}>{season}</option>
                ))}
              </select>
            </label>

            {!!availableSelections.length && (
              <p className="result-availability-note">
                Available demo result combinations:{" "}
                {availableSelections
                  .map((selection) => `Sem ${selection.semester} ${selection.exam} ${selection.year} ${selection.season}`)
                  .join(" | ")}
              </p>
            )}

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
