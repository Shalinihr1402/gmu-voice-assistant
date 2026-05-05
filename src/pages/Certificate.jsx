import { useEffect, useMemo, useState } from "react"
import { useNavigate } from "react-router-dom"

import { fetchJson } from "../utils/api"
import "./Certificate.css"

const CERTIFICATE_ROWS = [
  { academicYear: "2024-25", season: "ODD", program: "MCA", sem: "1", code: "HG24TCCYS1", subject: "Fundamentals of Cyber Security", grade: "O", date: "12-06-2025" },
  { academicYear: "2024-25", season: "EVEN", program: "MCA", sem: "2", code: "HG24TCCYS2", subject: "Cybersecurity Essentials - Ethical Hacking (Stage 2)", grade: "A+", date: "24-10-2025" },
  { academicYear: "2024-25", season: "EVEN", program: "MCA", sem: "2", code: "HG24SATC02", subject: "Co-curricular Activities", grade: "A+", date: "02-04-2026" },
  { academicYear: "2025-26", season: "ODD", program: "MCA", sem: "3", code: "HG24TCESIE", subject: "Technical Skills", grade: "O", date: "11-04-2026" },
  { academicYear: "2025-26", season: "ODD", program: "MCA", sem: "3", code: "HG24SATC02", subject: "Co-curricular Activities", grade: "A", date: "11-04-2026" }
]

const Certificate = () => {
  const navigate = useNavigate()
  const [student, setStudent] = useState(null)
  const [selectedCertificate, setSelectedCertificate] = useState(null)

  useEffect(() => {
    fetchJson("getProfile.php")
      .then((data) => {
        if (data.error) {
          navigate("/")
          return
        }

        setStudent(data)
      })
      .catch(() => navigate("/"))
  }, [navigate])

  const certificates = useMemo(() => {
    if (!student) {
      return []
    }

    return CERTIFICATE_ROWS.map((row) => ({
      ...row,
      usn: student.usn || "NA",
      name: student.full_name || "Student"
    }))
  }, [student])

  useEffect(() => {
    if (certificates.length && !selectedCertificate) {
      setSelectedCertificate(certificates[0])
    }
  }, [certificates, selectedCertificate])

  if (!student) {
    return <div className="certificate-loading">Loading certificates...</div>
  }

  return (
    <div className="certificate-page">
      <header className="certificate-header">
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
          <a href="#" onClick={(event) => { event.preventDefault(); navigate("/results") }}>
            Student Result
          </a>
          <a href="#" className="active" onClick={(event) => event.preventDefault()}>
            Digital Competency Certificate
          </a>
        </nav>

        <div className="header-user">
          {student.full_name}
        </div>
      </header>

      <main className="certificate-main">
        <section className="certificate-shell">
          <div className="certificate-toolbar">
            <div>
              <h1>Digital Competency Certificate</h1>
              <p>View subject-wise certificates earned in your academic journey.</p>
            </div>
            <div className="certificate-count">Displaying {certificates.length} certificates</div>
          </div>

          <div className="certificate-table-wrap">
            <table className="certificate-table">
              <thead>
                <tr>
                  <th>ACTION</th>
                  <th>ACADEMIC YEAR</th>
                  <th>SEASON</th>
                  <th>PROGRAM</th>
                  <th>SEM</th>
                  <th>CODE</th>
                  <th>SUBJECT</th>
                  <th>USN</th>
                  <th>NAME</th>
                  <th>GRADE</th>
                  <th>DATE</th>
                </tr>
              </thead>
              <tbody>
                {certificates.map((certificate) => (
                  <tr key={`${certificate.code}-${certificate.date}`}>
                    <td>
                      <button
                        className="certificate-action"
                        type="button"
                        onClick={() => setSelectedCertificate(certificate)}
                      >
                        Certificate
                      </button>
                    </td>
                    <td>{certificate.academicYear}</td>
                    <td>{certificate.season}</td>
                    <td>{certificate.program}</td>
                    <td>{certificate.sem}</td>
                    <td>{certificate.code}</td>
                    <td>{certificate.subject}</td>
                    <td>{certificate.usn}</td>
                    <td>{certificate.name}</td>
                    <td>{certificate.grade}</td>
                    <td>{certificate.date}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>

        {selectedCertificate && (
          <section className="certificate-preview">
            <div className="preview-card">
              <span className="preview-badge">Digital Certificate</span>
              <h2>{selectedCertificate.subject}</h2>
              <p>
                This certifies that <strong>{selectedCertificate.name}</strong> with USN{" "}
                <strong>{selectedCertificate.usn}</strong> has successfully completed{" "}
                <strong>{selectedCertificate.subject}</strong> during {selectedCertificate.academicYear} ({selectedCertificate.season})
                and secured grade <strong>{selectedCertificate.grade}</strong>.
              </p>
              <div className="preview-meta">
                <span>Program: {selectedCertificate.program}</span>
                <span>Semester: {selectedCertificate.sem}</span>
                <span>Code: {selectedCertificate.code}</span>
                <span>Issued on: {selectedCertificate.date}</span>
              </div>
              <button type="button" className="preview-print" onClick={() => window.print()}>
                Print Certificate
              </button>
            </div>
          </section>
        )}
      </main>
    </div>
  )
}

export default Certificate
