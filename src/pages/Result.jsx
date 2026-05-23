import { useEffect, useMemo, useRef, useState } from "react"
import { useLocation, useNavigate } from "react-router-dom"

import gmuLogo from "../assets/gmu-logo.png"
import { fetchJson } from "../utils/api"
import "./Result.css"

const uniqueValues = (values) => Array.from(new Set(values.filter(Boolean)))
const sortSemesters = (values) => [...values].sort((left, right) => Number(left) - Number(right))
const sortAcademicYears = (values) => [...values].sort((left, right) => right.localeCompare(left))

const gradeLetterFromPoint = (point) => {
  const value = Number(point)
  if (value >= 10) return "O"
  if (value >= 9) return "A+"
  if (value >= 8) return "A"
  if (value >= 7) return "B+"
  if (value >= 6) return "B"
  if (value > 0) return "C"
  return "F"
}

const pickDefaultSelection = (selections, semester) => {
  const semesterSelections = selections.filter((selection) => String(selection.semester) === String(semester))
  if (!semesterSelections.length) return null
  return semesterSelections.find((selection) => String(selection.exam).toUpperCase() === "SEE") || semesterSelections[0]
}

const performanceFeedbackFromSgpa = (sgpa) => {
  const value = Number(sgpa)
  if (Number.isNaN(value)) return "Keep checking your academic progress regularly."
  if (value >= 9.5) return "Outstanding performance. Keep up the excellent work."
  if (value >= 9) return "Excellent performance. You are doing very well."
  if (value >= 8) return "Very good performance. Keep maintaining this consistency."
  if (value >= 7) return "Good performance. With a little more focus, you can improve further."
  if (value >= 6) return "Average performance. Please focus more on weaker subjects."
  if (value >= 5) return "You have passed, but improvement is needed. Please revise regularly."
  return "Your performance needs serious attention. Please contact your mentor and work on improvement."
}

const buildResultSummary = (data) => {
  if (!data?.selection || !data?.summary) return ""

  const semester = data.selection.semester
  const exam = data.selection.exam || "SEE"
  const sgpa = data.summary.sgpa
  const feedback = performanceFeedbackFromSgpa(sgpa)

  return `Your ${semester} semester ${exam} result is now open. Your SGPA is ${sgpa}. ${feedback}`
}

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
  const resultAlreadySpokenRef = useRef(false)
  const lastVoiceSubmitRef = useRef({ key: "", at: 0 })
  const submitButtonRef = useRef(null)

  useEffect(() => {
    voicePrefillSubmittedRef.current = false
    resultAlreadySpokenRef.current = false
    setResultData(null)
    setErrorMessage("")
  }, [location.search])

  useEffect(() => {
    const params = new URLSearchParams(location.search)
    let storedRequest = null
    try {
      storedRequest = JSON.parse(sessionStorage.getItem("voicebot_result_request") || "null")
    } catch {
      storedRequest = null
    }
    const requestedSemester = params.get("semester") || storedRequest?.semester || ""

    Promise.all([
      fetchJson("getProfile.php"),
      fetchJson("getResultAvailability.php")
    ])
      .then(([profileData, availabilityData]) => {
        if (profileData.error) {
          navigate("/")
          return
        }

        const selections = Array.isArray(availabilityData?.selections) ? availabilityData.selections : []
        const defaultSelection = requestedSemester ? pickDefaultSelection(selections, requestedSemester) : null

        setStudent(profileData)
        setAvailableSelections(selections)
        setForm({
          usn: (params.get("usn") || storedRequest?.usn || profileData.usn || "").toUpperCase(),
          semester: requestedSemester,
          exam: params.get("exam") || storedRequest?.examType || defaultSelection?.exam || "SEE",
          year: params.get("year") || storedRequest?.year || defaultSelection?.year || "",
          season: params.get("season") || storedRequest?.season || defaultSelection?.season || ""
        })
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

  const buildFormFromVoiceRequest = (request) => {
    const requestedSemester = String(request?.semester || form.semester || "")
    const requestedExam = String(request?.examType || request?.exam || "").toUpperCase()
    const semesterSelections = availableSelections.filter((selection) => String(selection.semester) === String(requestedSemester))
    const defaultSelection = requestedSemester
      ? (requestedExam ? semesterSelections.find((selection) => String(selection.exam).toUpperCase() === requestedExam) : null) || pickDefaultSelection(availableSelections, requestedSemester)
      : null

    return {
      usn: String(request?.usn || form.usn || student?.usn || "").toUpperCase(),
      semester: requestedSemester,
      exam: String(requestedExam || defaultSelection?.exam || "SEE"),
      year: String(request?.year || defaultSelection?.year || form.year || ""),
      season: String(request?.season || defaultSelection?.season || form.season || "")
    }
  }
  const getResultRequestKey = (payload) => ([
    String(payload?.usn || "").toUpperCase(),
    String(payload?.semester || ""),
    String(payload?.exam || payload?.examType || "").toUpperCase(),
    String(payload?.year || ""),
    String(payload?.season || "").toUpperCase()
  ].join("|"))

  const shouldSkipDuplicateVoiceSubmit = (payload) => {
    const requestKey = getResultRequestKey(payload)
    const now = Date.now()
    const lastSubmit = lastVoiceSubmitRef.current

    if (lastSubmit.key === requestKey && now - lastSubmit.at < 15000) {
      return true
    }

    lastVoiceSubmitRef.current = { key: requestKey, at: now }
    return false
  }
  const buildUnavailableResultMessage = (payload, fallbackMessage = "") => {
    const semesterText = payload?.semester ? `semester ${payload.semester}` : "the selected semester"
    const examText = String(payload?.exam || payload?.examType || "selected exam").toUpperCase()
    const yearText = payload?.year ? ` for academic year ${payload.year}` : ""
    const seasonText = payload?.season ? ` ${String(payload.season).toUpperCase()} season` : ""
    const availableText = String(fallbackMessage || "").match(/Available combinations[^.]*\./)?.[0] || ""

    return `No published ${examText} result is available for ${semesterText}${yearText}${seasonText}.${availableText ? ` ${availableText}` : " Please check the exam type, year, and season, or contact the exam section."}`
  }

  const publishVoiceResultMessage = (summary, type = "result") => {
    const message = String(summary || "").trim()
    if (!message) return

    const resultSummaryPayload = { summary: message, type }
    sessionStorage.setItem("voicebot_result_summary", JSON.stringify(resultSummaryPayload))
    window.dispatchEvent(new CustomEvent("gmu:result-ready", {
      detail: resultSummaryPayload
    }))
  }

  const fetchResult = async (payload) => {
    setSubmitting(true)
    setErrorMessage("")
    setResultData(null)

    try {
      const data = await fetchJson("getSemesterResult.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      })

      setResultData(data)
      window.requestAnimationFrame(() => {
        const gradeSheet = document.querySelector("#grade-sheet-card")
        if (gradeSheet) {
          gradeSheet.scrollIntoView({ behavior: "smooth", block: "start" })
        } else {
          window.scrollTo({ top: 0, behavior: "smooth" })
        }
      })
    } catch (error) {
      const message = buildUnavailableResultMessage(payload, error.message || "")
      const openedByVoiceBot = sessionStorage.getItem("voicebot_result_opened") === "true"
      if (openedByVoiceBot) {
        publishVoiceResultMessage(message, "error")
      } else {
        sessionStorage.removeItem("voicebot_result_opened")
      }
      setErrorMessage(message || error.message || "Unable to fetch result right now.")
    } finally {
      setSubmitting(false)
    }
  }

  useEffect(() => {
    const handleVoicebotResultRequest = (event) => {
      if (!student || !availableSelections.length) return

      const payload = buildFormFromVoiceRequest(event.detail || {})
      if (!payload.usn || !payload.semester || !payload.exam || !payload.year || !payload.season) return
      if (shouldSkipDuplicateVoiceSubmit(payload)) return

      voicePrefillSubmittedRef.current = true
      resultAlreadySpokenRef.current = false
      setResultData(null)
      setErrorMessage("")
      setForm(payload)

      sessionStorage.setItem("voicebot_result_opened", "true")
      void fetchResult(payload)
    }

    window.addEventListener("gmu:voicebot-result-request", handleVoicebotResultRequest)
    return () => window.removeEventListener("gmu:voicebot-result-request", handleVoicebotResultRequest)
  }, [availableSelections, form, student])

  const handleChange = (field, value) => {
    setForm((current) => {
      if (field === "semester") {
        const defaultSelection = pickDefaultSelection(availableSelections, value)
        return {
          ...current,
          semester: value,
          exam: defaultSelection?.exam || "",
          year: defaultSelection?.year || "",
          season: defaultSelection?.season || ""
        }
      }

      if (field === "exam") {
        return { ...current, exam: value, year: "", season: "" }
      }

      if (field === "year") {
        return { ...current, year: value, season: "" }
      }

      return { ...current, [field]: value }
    })
  }

  useEffect(() => {
    const params = new URLSearchParams(location.search)
    const hasStoredRequest = sessionStorage.getItem("voicebot_result_request") !== null
    const voiceRequestedResult = hasStoredRequest || params.has("semester") || params.has("exam") || params.has("year") || params.has("season")

    if (!voiceRequestedResult || !student || submitting || voicePrefillSubmittedRef.current) return
    if (!form.usn || !form.semester || !form.exam || !form.year || !form.season) return

    voicePrefillSubmittedRef.current = true
    const payload = { ...form }
    if (shouldSkipDuplicateVoiceSubmit(payload)) return
    sessionStorage.setItem("voicebot_result_opened", "true")
    void fetchResult(payload)
  }, [form, location.search, student, submitting])

  useEffect(() => {
    if (!resultData || resultAlreadySpokenRef.current) return

    const openedByVoiceBot = sessionStorage.getItem("voicebot_result_opened")
    if (openedByVoiceBot !== "true") return

    const summary = buildResultSummary(resultData)
    if (!summary) return

    const gradeSheetReady = Boolean(
      document.querySelector("#grade-sheet-card")
      && document.querySelector(".provisional-table")
      && document.querySelector(".summary-table")
    )

    if (!gradeSheetReady) return

    resultAlreadySpokenRef.current = true
    sessionStorage.removeItem("voicebot_result_request")

    publishVoiceResultMessage(summary)
  }, [resultData])

  const handleSubmit = async (event) => {
    event.preventDefault()
    await fetchResult(form)
  }

  const handleDownload = () => {
    window.print()
  }

  const isVoiceResultLoading = submitting && sessionStorage.getItem("voicebot_result_opened") === "true" && !resultData && !errorMessage

  if (loading || !student || isVoiceResultLoading) {
    return <div className="result-loading">{isVoiceResultLoading ? "Loading result sheet..." : "Loading result page..."}</div>
  }

  return (
    <div className={resultData ? "result-page sheet-mode" : "result-page"}>
      {!resultData && (
        <header className="result-header">
          <div className="logo-text">GMU-ERP</div>

          <nav className="header-nav">
            <a href="#" onClick={(event) => { event.preventDefault(); navigate("/home") }}>Student Hallticket</a>
            <a href="#" onClick={(event) => { event.preventDefault(); navigate("/profile") }}>Profile</a>
            <a href="#" onClick={(event) => { event.preventDefault(); navigate("/registration") }}>Student Registration</a>
            <a href="#" className="active" onClick={(event) => event.preventDefault()}>Student Result</a>
            <a href="#" onClick={(event) => { event.preventDefault(); navigate("/certificate") }}>Digital Competency Certificate</a>
          </nav>

          <div className="header-user">{student.full_name} v</div>
        </header>
      )}

      <main className="result-main">
        {!resultData && (
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
                <select value={form.semester} onChange={(event) => handleChange("semester", event.target.value)}>
                  <option value="">Select Sem</option>
                  {semesterOptions.map((semester) => <option key={semester} value={semester}>{semester}</option>)}
                </select>
              </label>

              <label>
                <span>Exam</span>
                <select value={form.exam} onChange={(event) => handleChange("exam", event.target.value)}>
                  <option value="">Select Exam</option>
                  {examOptions.map((exam) => <option key={exam} value={exam}>{exam}</option>)}
                </select>
              </label>

              <label>
                <span>Exam Conducted Year</span>
                <select value={form.year} onChange={(event) => handleChange("year", event.target.value)}>
                  <option value="">Select Academic Year</option>
                  {academicYearOptions.map((year) => <option key={year} value={year}>{year}</option>)}
                </select>
              </label>

              <label>
                <span>Exam Conducted Season</span>
                <select value={form.season} onChange={(event) => handleChange("season", event.target.value)}>
                  <option value="">Select Season</option>
                  {seasonOptions.map((season) => <option key={season} value={season}>{season}</option>)}
                </select>
              </label>

              {errorMessage && <p className="result-error">{errorMessage}</p>}

              <div className="result-form-actions">
                <button id="submitBtn" ref={submitButtonRef} type="submit" disabled={submitting}>{submitting ? "Loading..." : "Submit"}</button>
              </div>
            </form>
          </section>
        )}

        {resultData && (
          <section className="provisional-sheet" id="grade-sheet-card">
            <div className="sheet-banner">
              <img src={gmuLogo} alt="GM University" />
              <div>
                <p>Srishyla Educational Trust (R)</p>
                <h1>GM UNIVERSITY</h1>
                <p>(Established under the Karnataka State Act No. 19 of 2023)</p>
                <p>P. B. Road, Davanagere, Karnataka - 577 006</p>
                <p>E-mail: info@gmu.ac.in, Website: www.gmu.ac.in</p>
              </div>
            </div>

            <div className="sheet-actions no-print">
              <button type="button" onClick={() => setResultData(null)}>Back to Search</button>
              <button type="button" onClick={handleDownload}>Download / Print</button>
            </div>

            <h2>PROVISIONAL GRADE SHEET</h2>

            <div className="sheet-meta">
              <div>
                <p><strong>Name</strong><span>:</span><b>{resultData.student.full_name}</b></p>
                <p><strong>USN</strong><span>:</span><b>{resultData.student.usn}</b></p>
                <p><strong>Program</strong><span>:</span><b>{resultData.student.branch}</b></p>
                <p><strong>Semester</strong><span>:</span><b>{resultData.selection.semester}</b></p>
                <p><strong>Academic Year</strong><span>:</span><b>{resultData.selection.year}</b></p>
              </div>
              <p className="sheet-exam"><strong>Exam :</strong> {resultData.selection.exam} {resultData.selection.season}</p>
            </div>

            <table className="provisional-table">
              <thead>
                <tr>
                  <th>SL.No</th>
                  <th>Course Code</th>
                  <th>Course Title</th>
                  <th>Credits</th>
                  <th>Grade Awarded</th>
                  <th>Grade Point</th>
                </tr>
              </thead>
              <tbody>
                {resultData.subjects.map((subject, index) => (
                  <tr key={`${subject.course_code}-${subject.course_title}`}>
                    <td>{index + 1}</td>
                    <td>{subject.course_code}</td>
                    <td>{subject.course_title}</td>
                    <td>{Number(subject.credits).toFixed(2)}</td>
                    <td>{gradeLetterFromPoint(subject.grade_point)}</td>
                    <td>{subject.grade_point}</td>
                  </tr>
                ))}
              </tbody>
            </table>

            <table className="summary-table">
              <tbody>
                <tr><th>Total Credits During the Semester</th><td>{resultData.summary.credits}</td></tr>
                <tr><th>SGPA</th><td>{resultData.summary.sgpa}</td></tr>
                <tr><th>Status</th><td>{resultData.summary.status}</td></tr>
                {resultData.summary.backlog_count > 0 && (
                  <tr><th>Backlogs</th><td>{resultData.summary.backlogs.join(", ")}</td></tr>
                )}
              </tbody>
            </table>

            <div className="signature-row">
              <div>Signature<br />Registrar Assessment and Evaluation</div>
              <div>Signature<br />Pro-Vice Chancellor</div>
            </div>

            <h3>Grade Explanation</h3>
            <table className="grade-explanation">
              <tbody>
                <tr><th>Grade Letter</th><th>Absolute Grading Marks Range</th><th>Grade Point</th></tr>
                <tr><td>O</td><td>91-100</td><td>10</td></tr>
                <tr><td>A+</td><td>81-90</td><td>9</td></tr>
                <tr><td>A</td><td>71-80</td><td>8</td></tr>
                <tr><td>B+</td><td>61-70</td><td>7</td></tr>
                <tr><td>B</td><td>50-60</td><td>6</td></tr>
                <tr><td>C</td><td>&lt;50</td><td>0</td></tr>
              </tbody>
            </table>

            <p className="sheet-note">
              Note: University is not responsible for any inadvertent error that may have crept in the results being published on ERP. The results published are for immediate information to the examinees. This cannot be treated as original Grade Sheet.
            </p>
          </section>
        )}
      </main>
    </div>
  )
}

export default Result
