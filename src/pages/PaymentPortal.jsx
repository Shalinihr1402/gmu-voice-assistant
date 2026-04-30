import { useEffect, useMemo, useState } from "react"
import { useNavigate } from "react-router-dom"

import gmuLogo from "../assets/gmu-logo.png"
import groupLogo from "../assets/logo.png"
import { fetchJson } from "../utils/api"
import { useUiLanguage } from "../utils/uiLanguage"
import "./PaymentPortal.css"

const GRIEVANCE_STORAGE_KEY = "gmu-payment-grievances"

const PAYMENT_ACTIONS = {
  en: [
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
  ],
  hi: [
    {
      id: "college",
      title: "कॉलेज / ट्यूशन फीस",
      heading: "कॉलेज और ट्यूशन फीस भुगतान",
      description: "नीचे दिख रहे बैलेंस को देखकर अपनी सेमेस्टर अकादमिक फीस भरें।",
      methods: ["UPI ऐप्स", "डेबिट या क्रेडिट कार्ड", "नेट बैंकिंग"],
      note: "पेमेंट के दौरान अपना यूएसएन और रजिस्टर्ड मोबाइल नंबर उपयोग करें।"
    },
    {
      id: "hostel",
      title: "होस्टल फीस",
      heading: "होस्टल फीस भुगतान",
      description: "अपने खाते के लिए होस्टल रेंट, मेस एडवांस, या होस्टल से जुड़े शुल्क भरें।",
      methods: ["UPI ऐप्स", "डेबिट या क्रेडिट कार्ड", "नेट बैंकिंग"],
      note: "यदि आप होस्टल छात्र नहीं हैं, तो यह विकल्प आप पर लागू नहीं हो सकता।"
    },
    {
      id: "skill",
      title: "स्किल/लेट-रजिस्ट्रेशन/अन्य फीस",
      heading: "स्किल और अन्य फीस भुगतान",
      description: "इस विकल्प का उपयोग स्किल डेवलपमेंट फीस, लेट रजिस्ट्रेशन फीस, या अन्य विशेष भुगतान के लिए करें।",
      methods: ["UPI ऐप्स", "डेबिट या क्रेडिट कार्ड", "नेट बैंकिंग"],
      note: "आगे बढ़ने से पहले सही फीस हेड चुनें।"
    },
    {
      id: "receipt",
      title: "रसीद डाउनलोड",
      heading: "रसीद डाउनलोड",
      description: "सफल ट्रांजैक्शन के बाद अपनी नवीनतम पेमेंट रसीद डाउनलोड करें।",
      methods: ["छात्र रसीद इतिहास", "नवीनतम सफल पेमेंट रिकॉर्ड"],
      note: "अगर आपकी पेमेंट अभी पूरी हुई है, तो कुछ मिनट बाद रिफ्रेश करें।"
    },
    {
      id: "grievance",
      title: "पेमेंट शिकायत",
      heading: "पेमेंट शिकायत दर्ज करें",
      description: "यहां failed deduction, pending receipt, या wrong fee mapping जैसी समस्याएं दर्ज करें।",
      methods: ["ट्रांजैक्शन रेफरेंस", "पेमेंट तिथि और राशि", "समस्या सारांश"],
      note: "अपना बैंक रेफरेंस या UPI ट्रांजैक्शन आईडी तैयार रखें।"
    },
    {
      id: "grievance-result",
      title: "शिकायत परिणाम",
      heading: "शिकायत परिणाम देखें",
      description: "अपनी जमा की गई पेमेंट शिकायत की नवीनतम स्थिति यहां ट्रैक करें।",
      methods: ["जमा शिकायत नंबर", "रजिस्टर्ड मोबाइल या यूएसएन"],
      note: "Resolved मामलों में accounts team द्वारा की गई कार्रवाई दिखाई देगी।"
    }
  ],
  kn: [
    {
      id: "college",
      title: "ಕಾಲೇಜು / ಟ್ಯೂಷನ್ ಶುಲ್ಕ",
      heading: "ಕಾಲೇಜು ಮತ್ತು ಟ್ಯೂಷನ್ ಶುಲ್ಕ ಪಾವತಿ",
      description: "ಕೆಳಗೆ ತೋರಿಸಿರುವ ಬಾಕಿ ಮೊತ್ತವನ್ನು ಪರಿಶೀಲಿಸಿ ನಿಮ್ಮ ಸೆಮಿಸ್ಟರ್ ಅಕಾಡೆಮಿಕ್ ಶುಲ್ಕ ಪಾವತಿಸಿ.",
      methods: ["UPI ಆ್ಯಪ್‌ಗಳು", "ಡೆಬಿಟ್ ಅಥವಾ ಕ್ರೆಡಿಟ್ ಕಾರ್ಡ್", "ನೆಟ್ ಬ್ಯಾಂಕಿಂಗ್"],
      note: "ಪಾವತಿ ಮಾಡುವಾಗ ನಿಮ್ಮ ಯುಎಸ್‌ಎನ್ ಮತ್ತು ನೋಂದಾಯಿತ ಮೊಬೈಲ್ ಸಂಖ್ಯೆಯನ್ನು ಬಳಸಿ."
    },
    {
      id: "hostel",
      title: "ಹಾಸ್ಟೆಲ್ ಶುಲ್ಕ",
      heading: "ಹಾಸ್ಟೆಲ್ ಶುಲ್ಕ ಪಾವತಿ",
      description: "ನಿಮ್ಮ ಖಾತೆಗೆ ಸಂಬಂಧಿಸಿದ ಹಾಸ್ಟೆಲ್ ಬಾಡಿಗೆ, ಮೆಸ್ ಅಡ್ವಾನ್ಸ್, ಅಥವಾ ಹಾಸ್ಟೆಲ್ ಶುಲ್ಕವನ್ನು ಪಾವತಿಸಿ.",
      methods: ["UPI ಆ್ಯಪ್‌ಗಳು", "ಡೆಬಿಟ್ ಅಥವಾ ಕ್ರೆಡಿಟ್ ಕಾರ್ಡ್", "ನೆಟ್ ಬ್ಯಾಂಕಿಂಗ್"],
      note: "ನೀವು ಹಾಸ್ಟೆಲ್ ವಿದ್ಯಾರ್ಥಿ ಅಲ್ಲದಿದ್ದರೆ, ಈ ಆಯ್ಕೆ ನಿಮಗೆ ಅನ್ವಯಿಸದೇ ಇರಬಹುದು."
    },
    {
      id: "skill",
      title: "ಸ್ಕಿಲ್/ಲೇಟ್-ರಿಜಿಸ್ಟ್ರೇಶನ್/ಇತರೆ ಶುಲ್ಕ",
      heading: "ಸ್ಕಿಲ್ ಮತ್ತು ಇತರೆ ಶುಲ್ಕ ಪಾವತಿ",
      description: "ಈ ಆಯ್ಕೆಯನ್ನು ಸ್ಕಿಲ್ ಡೆವಲಪ್‌ಮೆಂಟ್ ಶುಲ್ಕ, ಲೇಟ್ ರಿಜಿಸ್ಟ್ರೇಶನ್ ಶುಲ್ಕ, ಅಥವಾ ಇತರೆ ವಿಶೇಷ ಪಾವತಿಗಳಿಗೆ ಬಳಸಿ.",
      methods: ["UPI ಆ್ಯಪ್‌ಗಳು", "ಡೆಬಿಟ್ ಅಥವಾ ಕ್ರೆಡಿಟ್ ಕಾರ್ಡ್", "ನೆಟ್ ಬ್ಯಾಂಕಿಂಗ್"],
      note: "ಮುಂದುವರೆಯುವ ಮೊದಲು ಸರಿಯಾದ ಶುಲ್ಕ ಹೆಡ್ ಆಯ್ಕೆಮಾಡಿ."
    },
    {
      id: "receipt",
      title: "ರಸೀದಿ ಡೌನ್‌ಲೋಡ್",
      heading: "ರಸೀದಿ ಡೌನ್‌ಲೋಡ್",
      description: "ಯಶಸ್ವಿ ವಹಿವಾಟಿನ ನಂತರ ನಿಮ್ಮ ಇತ್ತೀಚಿನ ಪಾವತಿ ರಸೀದಿಯನ್ನು ಡೌನ್‌ಲೋಡ್ ಮಾಡಿ.",
      methods: ["ವಿದ್ಯಾರ್ಥಿ ರಸೀದಿ ಇತಿಹಾಸ", "ಇತ್ತೀಚಿನ ಯಶಸ್ವಿ ಪಾವತಿ ದಾಖಲೆ"],
      note: "ಪಾವತಿ ಇತ್ತೀಚೆಗೆ ಪೂರ್ಣಗೊಂಡಿದ್ದರೆ, ಕೆಲವು ನಿಮಿಷಗಳ ನಂತರ ರಿಫ್ರೆಶ್ ಮಾಡಿ."
    },
    {
      id: "grievance",
      title: "ಪಾವತಿ ಅಹವಾಲು",
      heading: "ಪಾವತಿ ಅಹವಾಲು ಸಲ್ಲಿಸಿ",
      description: "ಇಲ್ಲಿ failed deduction, pending receipt, ಅಥವಾ wrong fee mapping ಸಮಸ್ಯೆಗಳನ್ನು ದಾಖಲಿಸಿ.",
      methods: ["ವಹಿವಾಟು ರೆಫರೆನ್ಸ್", "ಪಾವತಿ ದಿನಾಂಕ ಮತ್ತು ಮೊತ್ತ", "ಸಮಸ್ಯೆ ಸಾರಾಂಶ"],
      note: "ನಿಮ್ಮ ಬ್ಯಾಂಕ್ ರೆಫರೆನ್ಸ್ ಅಥವಾ UPI ವಹಿವಾಟು ಐಡಿ ಸಿದ್ಧವಾಗಿರಲಿ."
    },
    {
      id: "grievance-result",
      title: "ಅಹವಾಲು ಫಲಿತಾಂಶ",
      heading: "ಅಹವಾಲು ಫಲಿತಾಂಶ ನೋಡಿ",
      description: "ನಿಮ್ಮ ಸಲ್ಲಿಸಿದ ಪಾವತಿ ಅಹವಾಲಿನ ಇತ್ತೀಚಿನ ಸ್ಥಿತಿಯನ್ನು ಇಲ್ಲಿ ಟ್ರ್ಯಾಕ್ ಮಾಡಿ.",
      methods: ["ಸಲ್ಲಿಸಿದ ಅಹವಾಲು ಸಂಖ್ಯೆ", "ನೋಂದಾಯಿತ ಮೊಬೈಲ್ ಅಥವಾ ಯುಎಸ್‌ಎನ್"],
      note: "Resolved ಪ್ರಕರಣಗಳಲ್ಲಿ accounts team ತೆಗೆದುಕೊಂಡ ಕ್ರಮವನ್ನು ತೋರಿಸಲಾಗುತ್ತದೆ."
    }
  ]
}

const getStoredGrievances = () => {
  try {
    const stored = window.localStorage.getItem(GRIEVANCE_STORAGE_KEY)
    const parsed = stored ? JSON.parse(stored) : []
    return Array.isArray(parsed) ? parsed : []
  } catch {
    return []
  }
}

const saveStoredGrievances = (items) => {
  window.localStorage.setItem(GRIEVANCE_STORAGE_KEY, JSON.stringify(items))
}

const createGrievanceId = () => `GRV-${Date.now().toString().slice(-8)}`

const PAGE_COPY = {
  en: {
    loading: "Loading payment portal...",
    trustLine: "SriShyla Education Trust(R), Bheemasamudra",
    tagline: "Your Gateway to Easy Transactions",
    welcome: "Welcome to GM UNIVERSITY Payment Portal!",
    student: "Student",
    usn: "USN",
    branch: "Branch",
    totalFee: "Total Fee",
    paid: "Paid",
    pendingBalance: "Pending Balance",
    availableOptions: "Available options",
    paymentSupport: "Payment Support",
    yourGrievance: "Your Grievance",
    grievanceIntro: "Use this form if your fee payment was deducted but not updated, receipt is missing, or the amount is mapped to the wrong fee head.",
    usnOrAadhaar: "USN / Aadhaar",
    usnPlaceholder: "Enter your USN or Aadhaar number",
    phone: "Phone",
    phonePlaceholder: "Your phone number",
    description: "Description",
    descriptionPlaceholder: "Write your issue here",
    uploadFile: "Upload File",
    chooseFile: "Choose file",
    grievanceHelp1: "Keep your payment date, amount, and bank or UPI reference ready.",
    grievanceHelp2: "If your fee status is not updated, mention the exact transaction amount and time.",
    grievanceValidation: "Please fill in USN, phone number, and issue description.",
    grievanceSubmitted: "Grievance submitted successfully. Your grievance number is",
    attachmentReceived: "Attachment received:",
    noAttachment: "No attachment uploaded",
    submit: "Submit",
    backToHome: "Back to Home",
    tracking: "Tracking",
    grievanceHistory: "Grievance History",
    search: "Search",
    searchPlaceholder: "USN, phone, or grievance number",
    latestUpdate: "Latest update:",
    on: "on",
    noGrievanceRecords: "No grievance records found for this search.",
    studentUsn: "Student USN",
    year: "Year",
    quota: "Quota",
    status: "Status",
    noGrievanceFound: "No grievance found",
    id: "ID",
    action: "Action",
    remarks: "Remarks",
    details: "Details",
    updatedOn: "Updated On",
    noTableData: "No data available in table",
    raiseNewGrievance: "Raise New Grievance",
    back: "Back",
    feeType: "Fee Type",
    backToRegistration: "Back to Registration",
    home: "Home",
    grievanceAction: "Payment grievance submitted",
    grievanceRemarks: "Submitted"
  },
  hi: {
    loading: "पेमेंट पोर्टल लोड हो रहा है...",
    trustLine: "श्रीश्यला एजुकेशन ट्रस्ट (आर), भीमसमुद्र",
    tagline: "आसान लेन-देन के लिए आपका पोर्टल",
    welcome: "GM UNIVERSITY पेमेंट पोर्टल में आपका स्वागत है!",
    student: "छात्र",
    usn: "यूएसएन",
    branch: "ब्रांच",
    totalFee: "कुल फीस",
    paid: "भरी गई",
    pendingBalance: "बाकी फीस",
    availableOptions: "उपलब्ध विकल्प",
    paymentSupport: "पेमेंट सहायता",
    yourGrievance: "आपकी शिकायत",
    grievanceIntro: "अगर आपकी फीस कट गई है लेकिन अपडेट नहीं हुई, रसीद नहीं मिली, या फीस गलत हेड में गई है, तो इस फॉर्म का उपयोग करें।",
    usnOrAadhaar: "यूएसएन / आधार",
    usnPlaceholder: "अपना यूएसएन या आधार नंबर दर्ज करें",
    phone: "फोन",
    phonePlaceholder: "अपना फोन नंबर",
    description: "विवरण",
    descriptionPlaceholder: "अपनी समस्या यहां लिखें",
    uploadFile: "फ़ाइल अपलोड करें",
    chooseFile: "फ़ाइल चुनें",
    grievanceHelp1: "अपनी पेमेंट तिथि, राशि, और बैंक या UPI रेफरेंस तैयार रखें।",
    grievanceHelp2: "अगर फीस स्टेटस अपडेट नहीं हुआ है, तो सही राशि और समय जरूर लिखें।",
    grievanceValidation: "कृपया यूएसएन, फोन नंबर और समस्या विवरण भरें।",
    grievanceSubmitted: "शिकायत सफलतापूर्वक जमा हो गई। आपका शिकायत नंबर है",
    attachmentReceived: "संलग्न फ़ाइल प्राप्त हुई:",
    noAttachment: "कोई फ़ाइल अपलोड नहीं की गई",
    submit: "जमा करें",
    backToHome: "होम पर जाएं",
    tracking: "ट्रैकिंग",
    grievanceHistory: "शिकायत इतिहास",
    search: "खोजें",
    searchPlaceholder: "यूएसएन, फोन, या शिकायत नंबर",
    latestUpdate: "नवीनतम अपडेट:",
    on: "को",
    noGrievanceRecords: "इस खोज के लिए कोई शिकायत रिकॉर्ड नहीं मिला।",
    studentUsn: "छात्र यूएसएन",
    year: "वर्ष",
    quota: "कोटा",
    status: "स्थिति",
    noGrievanceFound: "कोई शिकायत नहीं मिली",
    id: "आईडी",
    action: "कार्रवाई",
    remarks: "टिप्पणी",
    details: "विवरण",
    updatedOn: "अपडेट तिथि",
    noTableData: "टेबल में कोई डेटा उपलब्ध नहीं है",
    raiseNewGrievance: "नई शिकायत दर्ज करें",
    back: "वापस",
    feeType: "फीस प्रकार",
    backToRegistration: "रजिस्ट्रेशन पर वापस",
    home: "होम",
    grievanceAction: "पेमेंट शिकायत जमा की गई",
    grievanceRemarks: "जमा"
  },
  kn: {
    loading: "ಪೇಮೆಂಟ್ ಪೋರ್ಟಲ್ ಲೋಡ್ ಆಗುತ್ತಿದೆ...",
    trustLine: "ಶ್ರೀಶೈಲ ಎಜುಕೇಶನ್ ಟ್ರಸ್ಟ್ (ಆರ್), ಭೀಮಸಮುದ್ರ",
    tagline: "ಸುಲಭ ವಹಿವಾಟಿನ ನಿಮ್ಮ ದ್ವಾರ",
    welcome: "GM UNIVERSITY ಪೇಮೆಂಟ್ ಪೋರ್ಟಲ್‌ಗೆ ಸ್ವಾಗತ!",
    student: "ವಿದ್ಯಾರ್ಥಿ",
    usn: "ಯುಎಸ್‌ಎನ್",
    branch: "ಶಾಖೆ",
    totalFee: "ಒಟ್ಟು ಶುಲ್ಕ",
    paid: "ಪಾವತಿಸಿದುದು",
    pendingBalance: "ಬಾಕಿ ಶುಲ್ಕ",
    availableOptions: "ಲಭ್ಯ ಆಯ್ಕೆಗಳು",
    paymentSupport: "ಪಾವತಿ ಸಹಾಯ",
    yourGrievance: "ನಿಮ್ಮ ಅಹವಾಲು",
    grievanceIntro: "ನಿಮ್ಮ ಶುಲ್ಕ ಕಟ್ ಆಗಿ ಸ್ಟೇಟಸ್ ಅಪ್‌ಡೇಟ್ ಆಗಿಲ್ಲದಿದ್ದರೆ, ರಸೀದಿ ಸಿಗದಿದ್ದರೆ, ಅಥವಾ ಶುಲ್ಕ ತಪ್ಪು ಹೆಡ್‌ಗೆ ಹೋಗಿದ್ದರೆ ಈ ಫಾರ್ಮ್ ಬಳಸಿ.",
    usnOrAadhaar: "ಯುಎಸ್‌ಎನ್ / ಆಧಾರ್",
    usnPlaceholder: "ನಿಮ್ಮ ಯುಎಸ್‌ಎನ್ ಅಥವಾ ಆಧಾರ್ ಸಂಖ್ಯೆಯನ್ನು ನಮೂದಿಸಿ",
    phone: "ಫೋನ್",
    phonePlaceholder: "ನಿಮ್ಮ ಫೋನ್ ಸಂಖ್ಯೆ",
    description: "ವಿವರಣೆ",
    descriptionPlaceholder: "ನಿಮ್ಮ ಸಮಸ್ಯೆಯನ್ನು ಇಲ್ಲಿ ಬರೆಯಿರಿ",
    uploadFile: "ಫೈಲ್ ಅಪ್‌ಲೋಡ್",
    chooseFile: "ಫೈಲ್ ಆಯ್ಕೆಮಾಡಿ",
    grievanceHelp1: "ನಿಮ್ಮ ಪಾವತಿ ದಿನಾಂಕ, ಮೊತ್ತ, ಮತ್ತು ಬ್ಯಾಂಕ್ ಅಥವಾ UPI ರೆಫರೆನ್ಸ್ ಸಿದ್ಧವಾಗಿರಲಿ.",
    grievanceHelp2: "ಫೀ ಸ್ಟೇಟಸ್ ಅಪ್‌ಡೇಟ್ ಆಗದಿದ್ದರೆ, ಸರಿಯಾದ ಮೊತ್ತ ಮತ್ತು ಸಮಯವನ್ನು ನಮೂದಿಸಿ.",
    grievanceValidation: "ದಯವಿಟ್ಟು ಯುಎಸ್‌ಎನ್, ಫೋನ್ ಸಂಖ್ಯೆ ಮತ್ತು ಸಮಸ್ಯೆ ವಿವರಣೆ ತುಂಬಿ.",
    grievanceSubmitted: "ಅಹವಾಲು ಯಶಸ್ವಿಯಾಗಿ ಸಲ್ಲಿಸಲಾಗಿದೆ. ನಿಮ್ಮ ಅಹವಾಲು ಸಂಖ್ಯೆ",
    attachmentReceived: "ಲಗತ್ತಿಸಿದ ಫೈಲ್ ಸ್ವೀಕರಿಸಲಾಗಿದೆ:",
    noAttachment: "ಯಾವುದೇ ಫೈಲ್ ಅಪ್‌ಲೋಡ್ ಆಗಿಲ್ಲ",
    submit: "ಸಲ್ಲಿಸಿ",
    backToHome: "ಹೋಮ್‌ಗೆ ಹಿಂತಿರುಗಿ",
    tracking: "ಟ್ರ್ಯಾಕಿಂಗ್",
    grievanceHistory: "ಅಹವಾಲು ಇತಿಹಾಸ",
    search: "ಹುಡುಕಿ",
    searchPlaceholder: "ಯುಎಸ್‌ಎನ್, ಫೋನ್, ಅಥವಾ ಅಹವಾಲು ಸಂಖ್ಯೆ",
    latestUpdate: "ಇತ್ತೀಚಿನ ಅಪ್‌ಡೇಟ್:",
    on: "ರಂದು",
    noGrievanceRecords: "ಈ ಹುಡುಕಾಟಕ್ಕೆ ಯಾವುದೇ ಅಹವಾಲು ದಾಖಲೆ ಸಿಗಲಿಲ್ಲ.",
    studentUsn: "ವಿದ್ಯಾರ್ಥಿ ಯುಎಸ್‌ಎನ್",
    year: "ವರ್ಷ",
    quota: "ಕೋಟಾ",
    status: "ಸ್ಥಿತಿ",
    noGrievanceFound: "ಯಾವುದೇ ಅಹವಾಲು ಸಿಗಲಿಲ್ಲ",
    id: "ಐಡಿ",
    action: "ಕ್ರಮ",
    remarks: "ಟಿಪ್ಪಣಿ",
    details: "ವಿವರಗಳು",
    updatedOn: "ನವೀಕರಿಸಿದ ದಿನಾಂಕ",
    noTableData: "ಪಟ್ಟಿಯಲ್ಲಿ ಯಾವುದೇ ಡೇಟಾ ಲಭ್ಯವಿಲ್ಲ",
    raiseNewGrievance: "ಹೊಸ ಅಹವಾಲು ಸಲ್ಲಿಸಿ",
    back: "ಹಿಂದೆ",
    feeType: "ಶುಲ್ಕ ಪ್ರಕಾರ",
    backToRegistration: "ನೋಂದಣಿಗೆ ಹಿಂತಿರುಗಿ",
    home: "ಹೋಮ್",
    grievanceAction: "ಪಾವತಿ ಅಹವಾಲು ಸಲ್ಲಿಸಲಾಗಿದೆ",
    grievanceRemarks: "ಸಲ್ಲಿಸಲಾಗಿದೆ"
  }
}

const PaymentPortal = () => {
  const navigate = useNavigate()
  const uiLanguage = useUiLanguage()
  const copy = PAGE_COPY[uiLanguage] || PAGE_COPY.en
  const paymentActions = PAYMENT_ACTIONS[uiLanguage] || PAYMENT_ACTIONS.en
  const [student, setStudent] = useState(null)
  const [payments, setPayments] = useState([])
  const [selectedAction, setSelectedAction] = useState("college")
  const [loading, setLoading] = useState(true)
  const [grievanceForm, setGrievanceForm] = useState({
    usn: "",
    phone: "",
    description: "",
    fileName: ""
  })
  const [grievanceLookup, setGrievanceLookup] = useState("")
  const [grievanceMessage, setGrievanceMessage] = useState("")
  const [grievances, setGrievances] = useState([])

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
        setGrievanceForm((current) => ({
          ...current,
          usn: studentData?.usn || current.usn,
          phone: studentData?.phone || studentData?.mobile || current.phone
        }))
        setGrievanceLookup(studentData?.usn || "")
        setGrievances(getStoredGrievances())
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

  const selectedMeta = paymentActions.find((item) => item.id === selectedAction) || paymentActions[0]

  const formatCurrency = (value) => (
    new Intl.NumberFormat("en-IN", {
      minimumFractionDigits: 0,
      maximumFractionDigits: 0
    }).format(Number(value || 0))
  )

  const formatDateTime = (value) => {
    if (!value) {
      return "--"
    }

    return new Intl.DateTimeFormat("en-IN", {
      day: "2-digit",
      month: "short",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit"
    }).format(new Date(value))
  }

  const handleGrievanceChange = (event) => {
    const { name, value } = event.target
    setGrievanceForm((current) => ({
      ...current,
      [name]: value
    }))
  }

  const handleGrievanceFileChange = (event) => {
    const file = event.target.files?.[0]
    setGrievanceForm((current) => ({
      ...current,
      fileName: file?.name || ""
    }))
  }

  const handleGrievanceSubmit = (event) => {
    event.preventDefault()

    const trimmedUsn = grievanceForm.usn.trim()
    const trimmedPhone = grievanceForm.phone.trim()
    const trimmedDescription = grievanceForm.description.trim()

    if (!trimmedUsn || !trimmedPhone || !trimmedDescription) {
      setGrievanceMessage(copy.grievanceValidation)
      return
    }

    const newEntry = {
      id: createGrievanceId(),
      usn: trimmedUsn,
      phone: trimmedPhone,
      description: trimmedDescription,
      action: copy.grievanceAction,
      remarks: copy.grievanceRemarks,
      details: grievanceForm.fileName
        ? `${copy.attachmentReceived} ${grievanceForm.fileName}`
        : copy.noAttachment,
      updatedOn: new Date().toISOString()
    }

    const nextItems = [newEntry, ...grievances]
    saveStoredGrievances(nextItems)
    setGrievances(nextItems)
    setGrievanceLookup(trimmedUsn)
    setGrievanceForm((current) => ({
      ...current,
      description: "",
      fileName: ""
    }))
    setGrievanceMessage(`${copy.grievanceSubmitted} ${newEntry.id}.`)
    setSelectedAction("grievance-result")
  }

  const normalizedLookup = grievanceLookup.trim().toLowerCase()
  const filteredGrievances = normalizedLookup
    ? grievances.filter((item) => {
      const usn = String(item.usn || "").toLowerCase()
      const phone = String(item.phone || "").toLowerCase()
      const grievanceId = String(item.id || "").toLowerCase()
      return usn.includes(normalizedLookup) || phone.includes(normalizedLookup) || grievanceId.includes(normalizedLookup)
    })
    : grievances.filter((item) => String(item.usn || "").toLowerCase() === String(student?.usn || "").toLowerCase())

  const latestGrievance = filteredGrievances[0] || null

  if (loading) {
    return <div className="payment-portal-loading">{copy.loading}</div>
  }

  return (
    <div className="payment-portal-page">
      <main className="payment-portal-shell">
        <section className="payment-hero">
          <img src={gmuLogo} alt="GM University" className="payment-hero-logo payment-hero-logo-left" />

          <div className="payment-hero-copy">
            <p className="payment-trust-line">{copy.trustLine}</p>
            <h1>GM SMART PAY</h1>
            <p className="payment-tagline">{copy.tagline}</p>
          </div>

          <img src={groupLogo} alt="GM Group" className="payment-hero-logo payment-hero-logo-right" />
        </section>

        <div className="payment-divider" />

        <h2 className="payment-welcome">{copy.welcome}</h2>

        <section className="payment-card-grid">
          {paymentActions.map((action) => (
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
              <span className="label">{copy.student}</span>
              <strong>{student?.full_name || copy.student}</strong>
            </div>
            <div>
              <span className="label">{copy.usn}</span>
              <strong>{student?.usn || "--"}</strong>
            </div>
            <div>
              <span className="label">{copy.branch}</span>
              <strong>{student?.branch || "--"}</strong>
            </div>
          </div>

          <div className="payment-summary-grid">
            <div className="payment-summary-box">
              <span>{copy.totalFee}</span>
              <strong>Rs. {formatCurrency(totalFee)}</strong>
            </div>
            <div className="payment-summary-box">
              <span>{copy.paid}</span>
              <strong>Rs. {formatCurrency(totalPaid)}</strong>
            </div>
            <div className="payment-summary-box balance">
              <span>{copy.pendingBalance}</span>
              <strong>Rs. {formatCurrency(totalBalance)}</strong>
            </div>
          </div>

          <div className="payment-detail-card">
            <h3>{selectedMeta.heading}</h3>
            <p>{selectedMeta.description}</p>

            <div className="payment-methods">
              <span>{copy.availableOptions}</span>
              <ul>
                {selectedMeta.methods.map((method) => (
                  <li key={method}>{method}</li>
                ))}
              </ul>
            </div>

            <p className="payment-note">{selectedMeta.note}</p>
          </div>

          {selectedAction === "grievance" && (
            <section className="grievance-form-card">
              <div className="grievance-form-header">
                <div>
                  <span className="grievance-kicker">{copy.paymentSupport}</span>
                  <h3>{copy.yourGrievance}</h3>
                </div>
                <p>{copy.grievanceIntro}</p>
              </div>

              <form className="grievance-form" onSubmit={handleGrievanceSubmit}>
                <label>
                  {copy.usnOrAadhaar}
                  <input
                    type="text"
                    name="usn"
                    placeholder={copy.usnPlaceholder}
                    value={grievanceForm.usn}
                    onChange={handleGrievanceChange}
                  />
                </label>

                <label>
                  {copy.phone}
                  <input
                    type="text"
                    name="phone"
                    placeholder={copy.phonePlaceholder}
                    value={grievanceForm.phone}
                    onChange={handleGrievanceChange}
                  />
                </label>

                <label>
                  {copy.description}
                  <textarea
                    name="description"
                    placeholder={copy.descriptionPlaceholder}
                    value={grievanceForm.description}
                    onChange={handleGrievanceChange}
                    rows="5"
                  />
                </label>

                <label className="grievance-file-field">
                  {copy.uploadFile}
                  <input type="file" onChange={handleGrievanceFileChange} />
                  <span>{grievanceForm.fileName || copy.chooseFile}</span>
                </label>

                <div className="grievance-inline-help">
                  <p>{copy.grievanceHelp1}</p>
                  <p>{copy.grievanceHelp2}</p>
                </div>

                {grievanceMessage && <p className="grievance-message">{grievanceMessage}</p>}

                <div className="grievance-actions">
                  <button type="submit" className="grievance-submit-btn">{copy.submit}</button>
                  <button type="button" className="grievance-home-btn" onClick={() => navigate("/home")}>
                    {copy.backToHome}
                  </button>
                </div>
              </form>
            </section>
          )}

          {selectedAction === "grievance-result" && (
            <section className="grievance-result-layout">
              <aside className="grievance-profile-card">
                <div className="grievance-avatar">{(student?.full_name || "S").trim().charAt(0)}</div>
                <h3>{student?.full_name || "Student"}</h3>

                <table>
                  <tbody>
                    <tr>
                      <td>{copy.studentUsn}</td>
                      <td>{student?.usn || "--"}</td>
                    </tr>
                    <tr>
                      <td>{copy.year}</td>
                      <td>{student?.semester || "--"}</td>
                    </tr>
                    <tr>
                      <td>{copy.branch}</td>
                      <td>{student?.branch || "--"}</td>
                    </tr>
                    <tr>
                      <td>{copy.quota}</td>
                      <td>{student?.quota || "--"}</td>
                    </tr>
                    <tr>
                      <td>{copy.status}</td>
                      <td>{latestGrievance ? latestGrievance.remarks : copy.noGrievanceFound}</td>
                    </tr>
                  </tbody>
                </table>
              </aside>

              <div className="grievance-history-card">
                <div className="grievance-history-topbar">
                  <div>
                    <span className="grievance-kicker">{copy.tracking}</span>
                    <h3>{copy.grievanceHistory}</h3>
                  </div>

                  <label className="grievance-search">
                    {copy.search}
                    <input
                      type="text"
                      placeholder={copy.searchPlaceholder}
                      value={grievanceLookup}
                      onChange={(event) => setGrievanceLookup(event.target.value)}
                    />
                  </label>
                </div>

                <div className="grievance-status-banner">
                  {latestGrievance
                    ? `${copy.latestUpdate} ${latestGrievance.remarks} ${copy.on} ${formatDateTime(latestGrievance.updatedOn)}`
                    : copy.noGrievanceRecords}
                </div>

                <div className="grievance-table-wrap">
                  <table className="grievance-history-table">
                    <thead>
                      <tr>
                        <th>{copy.id}</th>
                        <th>{copy.usn}</th>
                        <th>{copy.phone}</th>
                        <th>{copy.description}</th>
                        <th>{copy.action}</th>
                        <th>{copy.remarks}</th>
                        <th>{copy.details}</th>
                        <th>{copy.updatedOn}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {filteredGrievances.length ? filteredGrievances.map((item) => (
                        <tr key={item.id}>
                          <td>{item.id}</td>
                          <td>{item.usn}</td>
                          <td>{item.phone}</td>
                          <td>{item.description}</td>
                          <td>{item.action}</td>
                          <td>{item.remarks}</td>
                          <td>{item.details}</td>
                          <td>{formatDateTime(item.updatedOn)}</td>
                        </tr>
                      )) : (
                        <tr>
                          <td colSpan="8" className="grievance-empty-cell">{copy.noTableData}</td>
                        </tr>
                      )}
                    </tbody>
                  </table>
                </div>

                <div className="grievance-history-actions">
                  <button type="button" onClick={() => setSelectedAction("grievance")}>{copy.raiseNewGrievance}</button>
                  <button type="button" onClick={() => navigate("/payment")}>{copy.back}</button>
                </div>
              </div>
            </section>
          )}

          <div className="payment-table-wrap">
            <table className="payment-portal-table">
              <thead>
                <tr>
                  <th>{copy.feeType}</th>
                  <th>{copy.totalFee}</th>
                  <th>{copy.paid}</th>
                  <th>{copy.pendingBalance}</th>
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
            <button type="button" onClick={() => navigate("/registration")}>{copy.backToRegistration}</button>
            <button type="button" onClick={() => navigate("/home")}>{copy.home}</button>
          </div>
        </section>
      </main>
    </div>
  )
}

export default PaymentPortal
