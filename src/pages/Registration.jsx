import { useNavigate } from 'react-router-dom'
import { useEffect, useState } from 'react'
import './Registration.css'
import { fetchJson } from "../utils/api"
import { useUiLanguage } from "../utils/uiLanguage"

const Registration = () => {
  const navigate = useNavigate()
  const uiLanguage = useUiLanguage()

  const [student, setStudent] = useState(null)
  const [courses, setCourses] = useState([])
  const [payments, setPayments] = useState([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    const fetchData = async () => {
      try {
        // 1️⃣ Student
        const studentData = await fetchJson("getProfile.php")

        if (studentData.error) {
          navigate("/")
          return
        }

        setStudent(studentData)

        // 2️⃣ Courses
        const courseData = await fetchJson("getCourses.php")
        setCourses(courseData || [])

        // 3️⃣ Payments
        const paymentData = await fetchJson("getPaymentDetails.php")
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

  const grievanceHelpCopy = {
    en: {
      heading: "Payment grievance help",
      line1: "If your fee payment is deducted but status is not updated, open Payment, choose Payment Grievance, enter your USN, phone number, payment details, and upload proof if available.",
      line2: "To check the update later, open Grievance Result in the same payment portal and search using your USN, phone number, or grievance number."
    },
    hi: {
      heading: "पेमेंट शिकायत सहायता",
      line1: "अगर आपकी फीस कट गई है लेकिन स्टेटस अपडेट नहीं हुआ, तो Payment खोलें, Payment Grievance चुनें, अपना यूएसएन, फोन नंबर, पेमेंट विवरण भरें, और उपलब्ध होने पर प्रूफ अपलोड करें।",
      line2: "बाद में अपडेट देखने के लिए उसी पेमेंट पोर्टल में Grievance Result खोलें और यूएसएन, फोन नंबर, या शिकायत नंबर से खोजें।"
    },
    kn: {
      heading: "ಪಾವತಿ ಅಹವಾಲು ಸಹಾಯ",
      line1: "ನಿಮ್ಮ ಶುಲ್ಕ ಕಟ್ ಆಗಿ ಸ್ಟೇಟಸ್ ಅಪ್‌ಡೇಟ್ ಆಗದಿದ್ದರೆ Payment ತೆರೆಯಿರಿ, Payment Grievance ಆಯ್ಕೆಮಾಡಿ, ನಿಮ್ಮ ಯುಎಸ್‌ಎನ್, ಫೋನ್ ಸಂಖ್ಯೆ, ಪಾವತಿ ವಿವರಗಳನ್ನು ನಮೂದಿಸಿ, ಮತ್ತು ಪ್ರೂಫ್ ಇದ್ದರೆ ಅಪ್‌ಲೋಡ್ ಮಾಡಿ.",
      line2: "ನಂತರದ ಅಪ್‌ಡೇಟ್ ನೋಡಲು ಅದೇ ಪೇಮೆಂಟ್ ಪೋರ್ಟಲ್‌ನಲ್ಲಿ Grievance Result ತೆರೆಯಿರಿ ಮತ್ತು ಯುಎಸ್‌ಎನ್, ಫೋನ್ ಸಂಖ್ಯೆ, ಅಥವಾ ಅಹವಾಲು ಸಂಖ್ಯೆಯಿಂದ ಹುಡುಕಿ."
    }
  }[uiLanguage] || {
    heading: "Payment grievance help",
    line1: "If your fee payment is deducted but status is not updated, open Payment, choose Payment Grievance, enter your USN, phone number, payment details, and upload proof if available.",
    line2: "To check the update later, open Grievance Result in the same payment portal and search using your USN, phone number, or grievance number."
  }

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
               <tr key={index}>
      <td>{index + 1}</td>
      <td>{course.code}</td>
      <td>{course.title}</td>
      <td>{course.group}</td>
      <td>{course.type}</td>
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
          <button onClick={() => navigate('/payment')}>
            Payment
          </button>
        </div>

        <div className="payment-help-note">
          <h4>{grievanceHelpCopy.heading}</h4>
          <p>{grievanceHelpCopy.line1}</p>
          <p>{grievanceHelpCopy.line2}</p>
        </div>

      </main>
    </div>
  )
}

export default Registration
