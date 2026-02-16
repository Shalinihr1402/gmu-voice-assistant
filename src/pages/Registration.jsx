import { useNavigate } from 'react-router-dom'
import { useEffect, useState } from 'react'
import './Registration.css'

const Registration = () => {
  const navigate = useNavigate()

  const [student, setStudent] = useState(null)
  const [courses, setCourses] = useState([])
  const [payments, setPayments] = useState([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    const fetchData = async () => {
      try {
        // 1️⃣ Student
        const studentRes = await fetch(
          "http://localhost:8080/gmu-voice-assistant/backend/getProfile.php",
          { credentials: "include" }
        )
        const studentData = await studentRes.json()

        if (studentData.error) {
          navigate("/")
          return
        }

        setStudent(studentData)

        // 2️⃣ Courses
        const courseRes = await fetch(
          "http://localhost:8080/gmu-voice-assistant/backend/getCourses.php",
          { credentials: "include" }
        )
        const courseData = await courseRes.json()
        setCourses(courseData || [])

        // 3️⃣ Payments
        const paymentRes = await fetch(
          "http://localhost:8080/gmu-voice-assistant/backend/getPaymentDetails.php",
          { credentials: "include" }
        )
        const paymentData = await paymentRes.json()
        setPayments(paymentData || [])

        setLoading(false)

      } catch (error) {
        console.error("Registration Load Error:", error)
        navigate("/")
      }
    }

    fetchData()
  }, [navigate])

  if (loading) return <div>Loading...</div>

  // ✅ Check if all balances are 0
  const totalBalance = payments.reduce(
    (sum, p) => sum + Number(p.balance),
    0
  )

  return (
    <div className="registration-page">

      {/* HEADER */}
      <header className="reg-header">
        <div className="logo-text">GMU-ERP</div>

        <nav className="header-nav">
          <a href="#" onClick={(e) => { e.preventDefault(); navigate('/home') }}>
            Student Hallticket
          </a>
          <a href="#" onClick={(e) => { e.preventDefault(); navigate('/profile') }}>
            Profile
          </a>
          <a href="#" className="active">
            Student Registration
          </a>
        </nav>

        <div className="header-user">
          👤 {student.full_name} ▾
        </div>
      </header>

      <main className="reg-main">

        {/* University Header */}
        <div className="university-header">
          <h1>GM UNIVERSITY</h1>
          <p>
            Post Box No.4, P.B. Road, Davanagere - 577006,
            KARNATAKA | INDIA
          </p>
        </div>

        <h2 className="page-title">Course Registration</h2>

        {/* Student Info Section */}
        <div className="student-info-section">

          <div className="profile-photo">
            <div className="photo-placeholder">👤</div>
          </div>

          <div className="status-messages">
            {totalBalance > 0 ? (
              <p className="status-pending">
                Final Registration (Fees Payment) is Pending
              </p>
            ) : (
              <p className="status-complete">
                Final Registration Completed Successfully
              </p>
            )}
          </div>

          <div className="student-details-table">
            <table>
              <tbody>
                <tr><td>Name</td><td>{student.full_name}</td></tr>
                <tr><td>USN</td><td>{student.usn}</td></tr>
                <tr><td>Branch</td><td>{student.branch}</td></tr>
                <tr><td>Email</td><td>{student.email}</td></tr>
              </tbody>
            </table>
          </div>

        </div>

        {/* Course Details */}
        <div className="course-details-section">
          <h3>Course Details</h3>

          <table className="course-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Course Code</th>
                <th>Course</th>
                <th>Credits</th>
                <th>Type</th>
              </tr>
            </thead>
            <tbody>
              {courses.map((course, index) => (
                <tr key={course.course_id}>
                  <td>{index + 1}</td>
                  <td>{course.course_code}</td>
                  <td>{course.course_title}</td>
                  <td>{course.credits}</td>
                  <td>{course.course_type}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* Payment Details */}
        <div className="payment-details-section">
          <h3>Payment Details</h3>

          <table className="payment-table">
            <thead>
              <tr>
                <th>Fee Type</th>
                <th>Total Fee</th>
                <th>Paid</th>
                <th>Balance</th>
              </tr>
            </thead>
            <tbody>
              {payments.map((pay, index) => (
                <tr key={index}>
                  <td>{pay.fee_type}</td>
                  <td>₹ {pay.total_fee}</td>
                  <td>₹ {pay.paid}</td>
                  <td style={{ color: pay.balance > 0 ? "red" : "green" }}>
                    ₹ {pay.balance}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* Action Buttons */}
        <div className="action-buttons">
          <button onClick={() => navigate('/home')}>
            Home
          </button>
          <button onClick={() => window.print()}>
            Print
          </button>
          <button>
            Payment
          </button>
        </div>

      </main>
    </div>
  )
}

export default Registration
