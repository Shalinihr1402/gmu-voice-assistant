import { useEffect, useMemo, useState } from "react"
import { useNavigate } from "react-router-dom"

import gmuLogo from "../assets/gmu-logo.png"
import groupLogo from "../assets/logo.png"
import { fetchJson } from "../utils/api"
import "./PaymentPortal.css"

const PAYMENT_ACTIONS = [
  {
    id: "college",
    title: "College / Tution Fee",
    heading: "College and tuition fee payment",
    description: "Pay your semester academic fee after checking the balance shown below.",
    methods: ["UPI apps", "Debit or credit card", "Net banking"],
    note: "Use your USN and registered mobile number during payment."
  },
  {
    id: "hostel",
    title: "Hostel Fee",
    heading: "Hostel fee payment",
    description: "Pay hostel rent, mess advance, or hostel-related dues for your account.",
    methods: ["UPI apps", "Debit or credit card", "Net banking"],
    note: "If you are not a hostel student, this option may not apply to you."
  },
  {
    id: "skill",
    title: "Skill/Late-Registration/Other Fee",
    heading: "Skill and other fee payment",
    description: "Use this option for skill development fee, late registration fee, or other special payments.",
    methods: ["UPI apps", "Debit or credit card", "Net banking"],
    note: "Choose the correct fee head before proceeding with payment."
  },
  {
    id: "receipt",
    title: "Download Receipt",
    heading: "Receipt download",
    description: "Download your latest payment receipt after a successful transaction.",
    methods: ["Student receipt history", "Latest successful payment record"],
    note: "If your payment was just completed, wait a few minutes and refresh."
  },
  {
    id: "grievance",
    title: "Payment Grievance",
    heading: "Raise a payment grievance",
    description: "Report failed deduction, pending receipt, or wrong fee mapping issues here.",
    methods: ["Transaction reference", "Payment date and amount", "Issue summary"],
    note: "Keep your bank reference or UPI transaction ID ready."
  },
  {
    id: "grievance-result",
    title: "Grievance Result",
    heading: "Check grievance result",
    description: "Track the latest status of your submitted payment grievance.",
    methods: ["Submitted grievance number", "Registered mobile or USN"],
    note: "Resolved cases will show the action taken by the accounts team."
  }
]

const PaymentPortal = () => {
  const navigate = useNavigate()
  const [student, setStudent] = useState(null)
  const [payments, setPayments] = useState([])
  const [selectedAction, setSelectedAction] = useState("college")
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    const loadData = async () => {
      try {
        const [studentData, paymentData] = await Promise.all([
          fetchJson("getProfile.php"),
          fetchJson("getPaymentDetails.php")
        ])

        if (studentData?.error) {
          navigate("/")
          return
        }

        setStudent(studentData)
        setPayments(Array.isArray(paymentData) ? paymentData : [])
        setLoading(false)
      } catch (error) {
        console.error("Payment portal load error:", error)
        navigate("/")
      }
    }

    loadData()
  }, [navigate])

  const totalFee = useMemo(
    () => payments.reduce((sum, item) => sum + Number(item.total_fee || 0), 0),
    [payments]
  )
  const totalPaid = useMemo(
    () => payments.reduce((sum, item) => sum + Number(item.paid || 0), 0),
    [payments]
  )
  const totalBalance = useMemo(
    () => payments.reduce((sum, item) => sum + Number(item.balance || 0), 0),
    [payments]
  )

  const selectedMeta = PAYMENT_ACTIONS.find((item) => item.id === selectedAction) || PAYMENT_ACTIONS[0]

  const formatCurrency = (value) => (
    new Intl.NumberFormat("en-IN", {
      minimumFractionDigits: 0,
      maximumFractionDigits: 0
    }).format(Number(value || 0))
  )

  if (loading) {
    return <div className="payment-portal-loading">Loading payment portal...</div>
  }

  return (
    <div className="payment-portal-page">
      <main className="payment-portal-shell">
        <section className="payment-hero">
          <img src={gmuLogo} alt="GM University" className="payment-hero-logo payment-hero-logo-left" />

          <div className="payment-hero-copy">
            <p className="payment-trust-line">SriShyla Education Trust(R), Bheemasamudra</p>
            <h1>GM SMART PAY</h1>
            <p className="payment-tagline">Your Gateway to Easy Transactions</p>
          </div>

          <img src={groupLogo} alt="GM Group" className="payment-hero-logo payment-hero-logo-right" />
        </section>

        <div className="payment-divider" />

        <h2 className="payment-welcome">Welcome to GM UNIVERSITY Payment Portal!</h2>

        <section className="payment-card-grid">
          {PAYMENT_ACTIONS.map((action) => (
            <button
              key={action.id}
              type="button"
              className={`payment-action-btn${selectedAction === action.id ? " active" : ""}`}
              onClick={() => setSelectedAction(action.id)}
            >
              {action.title}
            </button>
          ))}
        </section>

        <section className="payment-info-panel">
          <div className="payment-student-strip">
            <div>
              <span className="label">Student</span>
              <strong>{student?.full_name || "Student"}</strong>
            </div>
            <div>
              <span className="label">USN</span>
              <strong>{student?.usn || "--"}</strong>
            </div>
            <div>
              <span className="label">Branch</span>
              <strong>{student?.branch || "--"}</strong>
            </div>
          </div>

          <div className="payment-summary-grid">
            <div className="payment-summary-box">
              <span>Total Fee</span>
              <strong>Rs. {formatCurrency(totalFee)}</strong>
            </div>
            <div className="payment-summary-box">
              <span>Paid</span>
              <strong>Rs. {formatCurrency(totalPaid)}</strong>
            </div>
            <div className="payment-summary-box balance">
              <span>Pending Balance</span>
              <strong>Rs. {formatCurrency(totalBalance)}</strong>
            </div>
          </div>

          <div className="payment-detail-card">
            <h3>{selectedMeta.heading}</h3>
            <p>{selectedMeta.description}</p>

            <div className="payment-methods">
              <span>Available options</span>
              <ul>
                {selectedMeta.methods.map((method) => (
                  <li key={method}>{method}</li>
                ))}
              </ul>
            </div>

            <p className="payment-note">{selectedMeta.note}</p>
          </div>

          <div className="payment-table-wrap">
            <table className="payment-portal-table">
              <thead>
                <tr>
                  <th>Fee Type</th>
                  <th>Total Fee</th>
                  <th>Paid</th>
                  <th>Balance</th>
                </tr>
              </thead>
              <tbody>
                {payments.map((payment, index) => (
                  <tr key={`${payment.fee_type}-${index}`}>
                    <td>{payment.fee_type}</td>
                    <td>Rs. {formatCurrency(payment.total_fee)}</td>
                    <td>Rs. {formatCurrency(payment.paid)}</td>
                    <td className={Number(payment.balance) > 0 ? "pending" : "clear"}>
                      Rs. {formatCurrency(payment.balance)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="payment-footer-actions">
            <button type="button" onClick={() => navigate("/registration")}>Back to Registration</button>
            <button type="button" onClick={() => navigate("/home")}>Home</button>
          </div>
        </section>
      </main>
    </div>
  )
}

export default PaymentPortal
