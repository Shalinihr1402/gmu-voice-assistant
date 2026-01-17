import { useNavigate } from 'react-router-dom'
import './Registration.css'

const Registration = () => {
  const navigate = useNavigate()

  return (
    <div className="registration-page">
      <header className="reg-header">
        <div className="header-left">
          <span className="logo-text">GMU-ERP</span>
        </div>
        <nav className="header-nav">
          <a href="#" onClick={(e) => { e.preventDefault(); navigate('/'); }}>Student Hallticket</a>
          <a href="#" onClick={(e) => { e.preventDefault(); navigate('/profile'); }}>Profile</a>
          <a href="#" onClick={(e) => { e.preventDefault(); navigate('/registration'); }}>Student Registration</a>
          <a href="#" onClick={(e) => { e.preventDefault(); }}>Student Result</a>
          <a href="#" onClick={(e) => { e.preventDefault(); }}>Competency Certificate</a>
        </nav>
        <div className="header-user">
          <span>IM SHIVAKUMARA ▾</span>
        </div>
      </header>

      <main className="reg-main">
        <div className="university-header">
          <h1>GM UNIVERSITY</h1>
          <p>Post Box No.4, P.B. Road, Davanagere - 577006, KARNATAKA | INDIA</p>
        </div>

        <h2 className="page-title">Course Registration</h2>

        <div className="student-info-section">
          <div className="profile-section">
            <div className="profile-photo">
              <div className="photo-placeholder">👤</div>
            </div>
            <div className="status-messages">
              <p className="status-pending">Final Registration(Fees Payment) is Pending</p>
              <p className="status-complete">You have Completed Course Registration</p>
            </div>
          </div>

          <div className="student-details-table">
            <table>
              <tbody>
                <tr>
                  <td><strong>Name:</strong></td>
                  <td>IM SHIVAKUMARA</td>
                </tr>
                <tr>
                  <td><strong>USN:</strong></td>
                  <td>P24C01CA026</td>
                </tr>
                <tr>
                  <td><strong>YEAR:</strong></td>
                  <td>2</td>
                </tr>
                <tr>
                  <td><strong>SEM:</strong></td>
                  <td>3</td>
                </tr>
                <tr>
                  <td><strong>BRANCH:</strong></td>
                  <td>MCA</td>
                </tr>
                <tr>
                  <td><strong>ACADEMIC YEAR:</strong></td>
                  <td>2025-26</td>
                </tr>
                <tr>
                  <td><strong>SEASON:</strong></td>
                  <td>ODD</td>
                </tr>
                <tr>
                  <td><strong>FACULTY:</strong></td>
                  <td>FCIT</td>
                </tr>
                <tr>
                  <td><strong>SCHOOL:</strong></td>
                  <td>SCA</td>
                </tr>
                <tr>
                  <td><strong>QUOTA:</strong></td>
                  <td>MGMT</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div className="course-details-section">
          <h3>Course Details</h3>
          <table className="course-table">
            <thead>
              <tr>
                <th>Course Code</th>
                <th>Course Name</th>
                <th>Group</th>
                <th>Type</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>MCA301</td>
                <td>Software Engineering and Project Management</td>
                <td>ACADEMIC</td>
                <td>CORE</td>
              </tr>
              <tr>
                <td>MCA302</td>
                <td>Digital Image Processing</td>
                <td>ACADEMIC</td>
                <td>CORE</td>
              </tr>
              <tr>
                <td>MCA303</td>
                <td>Web Technology</td>
                <td>ACADEMIC</td>
                <td>CORE</td>
              </tr>
              <tr>
                <td>MCA304</td>
                <td>Cloud Computing</td>
                <td>ACADEMIC</td>
                <td>ELECTIVE</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div className="payment-details-section">
          <h3>Payment Details</h3>
          <table className="payment-table">
            <thead>
              <tr>
                <th>Fee Type</th>
                <th>Total Fee</th>
                <th>To be paid for registration</th>
                <th>Paid</th>
                <th>Balance</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>Program Fee</td>
                <td>113631.00</td>
                <td>113631.00</td>
                <td>0.00</td>
                <td>113,631.00</td>
              </tr>
              <tr>
                <td>Skill/Other Assessment</td>
                <td>19369.00</td>
                <td>19369.00</td>
                <td>0.00</td>
                <td>19,369.00</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div className="guidelines-section">
          <h3>Student Registration Guidelines</h3>
          <ul>
            <li>Last date for course registration: 25-11-25</li>
            <li>Students must clear Tuition Fee and Skill Assessment Fee before registration</li>
            <li>Late Registration Fee must be paid after clearing outstanding balances</li>
            <li>Registration is incomplete if fees are pending</li>
            <li>Pressing the "Register Now" button after payment is mandatory</li>
          </ul>
        </div>

        <div className="action-buttons">
          <button className="action-btn home-btn" onClick={() => navigate('/')}>Home</button>
          <button className="action-btn print-btn">Print</button>
          <button className="action-btn payment-btn">Payment</button>
        </div>
      </main>
    </div>
  )
}

export default Registration
