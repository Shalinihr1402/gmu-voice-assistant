import 'dart:async';
import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:fl_chart/fl_chart.dart';
import 'package:http/http.dart' as http;
import 'package:permission_handler/permission_handler.dart';
import 'package:vapi/vapi.dart';
import 'src/browser_client_stub.dart'
    if (dart.library.html) 'src/browser_client_web.dart';
void main() => runApp(const GMUApp());

class Cfg {
  static const backend = String.fromEnvironment(
    'GMU_BACKEND_URL',
    defaultValue: 'http://localhost/gmu-voice-assistant/backend',
  );
}

class Clr {
  static const maroon = Color(0xFF851818);
  static const gold = Color(0xFFD8B63A);
  static const cream = Color(0xFFFFF4BD);
  static const page = Color(0xFFF3F3F3);
}

class GMUApp extends StatelessWidget {
  const GMUApp({super.key});
  @override
  Widget build(BuildContext context) => MaterialApp(
    title: 'GMU ERP',
    debugShowCheckedModeBanner: false,
    theme: ThemeData(
      colorScheme: ColorScheme.fromSeed(seedColor: Clr.maroon),
      useMaterial3: true,
    ),
    home: const LoginPage(),
  );
}

class Api {
  final http.Client _client = createHttpClient();
  String? cookie;
  Uri url(String p) =>
      Uri.parse('${Cfg.backend}/${p.replaceFirst(RegExp(r'^/+'), '')}');
  Map<String, String> headers([bool json = false]) {
    final values = <String, String>{
      'Accept': 'application/json',
      'Ngrok-Skip-Browser-Warning': 'true',
    };
    if (json) {
      values['Content-Type'] = 'application/json';
    }
    final savedCookie = cookie;
    if (!kIsWeb && savedCookie != null) {
      values['Cookie'] = savedCookie;
    }
    return values;
  }

  void saveCookie(http.Response r) {
    final c = r.headers['set-cookie'];
    if (c != null) cookie = c.split(';').first;
  }

  Future<dynamic> get(String p) async {
    final r = await _client
        .get(url(p), headers: headers())
        .timeout(const Duration(seconds: 20));
    saveCookie(r);
    return decode(r);
  }

  Future<dynamic> post(String p, Map<String, dynamic> body) async {
    final r = await _client
        .post(url(p), headers: headers(true), body: jsonEncode(body))
        .timeout(const Duration(seconds: 10));
    saveCookie(r);
    return decode(r);
  }

  dynamic decode(http.Response r) {
    if (r.statusCode < 200 || r.statusCode >= 300) {
      throw Exception('Server ${r.statusCode}');
    }
    final t = r.body.trim().replaceFirst('\uFEFF', '');
    if (t.isEmpty) return null;
    return jsonDecode(t);
  }
}

final api = Api();

class Student {
  const Student({
    required this.name,
    required this.usn,
    required this.branch,
    required this.email,
    required this.mobile,
    required this.aadhaar,
    required this.sem,
  });
  final String name, usn, branch, email, mobile, aadhaar, sem;
  factory Student.map(Map data) => Student(
    name: '${data['full_name'] ?? data['name'] ?? demo.name}',
    usn: '${data['usn'] ?? demo.usn}',
    branch: '${data['branch'] ?? data['program'] ?? demo.branch}',
    email: '${data['email'] ?? demo.email}',
    mobile: '${data['mobile_no'] ?? data['mobile'] ?? demo.mobile}',
    aadhaar: '${data['aadhaar_number'] ?? data['aadhaar'] ?? demo.aadhaar}',
    sem: '${data['semester'] ?? demo.sem}',
  );
  static const demo = Student(
    name: 'Aarav Kulkarni',
    usn: 'GMU22CSE001',
    branch: 'Computer Science',
    email: 'aarav@gmu.ac.in',
    mobile: '9876543210',
    aadhaar: '123412341234',
    sem: '5',
  );
}

class LoginPage extends StatefulWidget {
  const LoginPage({super.key});
  @override
  State<LoginPage> createState() => _LoginPageState();
}

class _LoginPageState extends State<LoginPage> {
  final id = TextEditingController(text: '123412341234');
  final pass = TextEditingController(text: '123456');
  bool loading = false;
  String? error;

  Future<void> login() async {
    setState(() {
      loading = true;
      error = null;
    });
    Student student = Student.demo;
    bool demo = false;
    try {
      final res = await api.post('login.php', {
        'loginId': id.text,
        'password': pass.text,
      });
      if (res is Map && res['success'] == true) {
        final profile = await api.get('getProfile.php');
        if (profile is Map && profile['error'] == null) {
          student = Student.map(profile);
        }
      } else {
        setState(() {
          loading = false;
          error = 'Invalid login details.';
        });
        return;
      }
    } catch (_) {
      demo = true;
    }
    if (!mounted) return;
    setState(() => loading = false);
    Navigator.pushReplacement(
      context,
      MaterialPageRoute(
        builder: (_) => ErpShell(student: student, demo: demo),
      ),
    );
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    backgroundColor: Clr.page,
    body: Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(22),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 460),
          child: Card(
            elevation: 10,
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(14),
            ),
            child: Padding(
              padding: const EdgeInsets.all(26),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Text(
                    'GM UNIVERSITY',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      color: Clr.maroon,
                      fontSize: 30,
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                  const SizedBox(height: 6),
                  const Text(
                    'University ERP Assistant Login',
                    textAlign: TextAlign.center,
                    style: TextStyle(fontSize: 17, color: Colors.black54),
                  ),
                  const SizedBox(height: 26),
                  Field(label: 'Login ID / Aadhaar', controller: id),
                  const SizedBox(height: 16),
                  Field(label: 'Password', controller: pass, obscure: true),
                  if (error != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 14),
                      child: Text(
                        error!,
                        textAlign: TextAlign.center,
                        style: const TextStyle(
                          color: Colors.red,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                    ),
                  const SizedBox(height: 22),
                  FilledButton(
                    style: FilledButton.styleFrom(
                      backgroundColor: Clr.gold,
                      foregroundColor: Colors.black,
                      padding: const EdgeInsets.all(16),
                    ),
                    onPressed: loading ? null : login,
                    child: Text(loading ? 'Logging in...' : 'Login'),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    ),
  );
}

class ErpShell extends StatefulWidget {
  const ErpShell({super.key, required this.student, required this.demo});
  final Student student;
  final bool demo;
  @override
  State<ErpShell> createState() => _ErpShellState();
}

class _ErpShellState extends State<ErpShell> {
  int page = 0;
  bool bot = false;
  String lang = 'English';
  String user = '';
  // Stores the last voice-requested result filter (semester, examType, year, season)
  // so ResultPage can show the correct grade sheet parameters.
  Map<String, String> resultFilter = {};
  late String reply =
      'Hello ${widget.student.name}. Welcome back to your GM University ERP Assistant. How can I help you today?';

  void open(int i, [String? msg]) => setState(() {
    page = i;
    reply = msg ?? '${titles[i]} page is open.';
  });
  static const titles = [
    'Home',
    'Profile',
    'Registration',
    'Payment Portal',
    'Student Result',
    'Certificate',
    'Dashboard',
    'Attendance',
    'Hall Ticket',
  ];

  void query(String q) {
    final s = q.toLowerCase();
    var r =
        'I can help with results, attendance, fees, registration, certificates, profile, and ERP services.';
    var p = page;
    if (s.contains('home') || s.contains('main') || s.contains('back')) {
      p = 0;
      r = 'Home page is open.';
    } else if (s.contains('profile')) {
      p = 1;
      r = 'Profile page is open.';
    } else if (s.contains('registration')) {
      p = 2;
      r = 'Registration page is open.';
    } else if (s.contains('payment') || s.contains('pay')) {
      p = 3;
      r = 'Payment portal is open.';
    } else if (s.contains('result') || s.contains('sgpa')) {
      p = 4;
      r = 'Student result page is open. Latest SGPA is 8.55 and status is PASS.';
    } else if (s.contains('certificate') || s.contains('competency')) {
      p = 5;
      r = 'Digital competency certificate page is open.';
    } else if (s.contains('dashboard')) {
      p = 6;
      r = 'Dashboard page is open.';
    } else if (s.contains('attendance')) {
      p = 7;
      r = 'Attendance page opened.';
    } else if (s.contains('fee')) {
      r = 'Fee balance summary: tuition fee balance Rs. 35000, skill fee balance Rs. 0, other fee balance Rs. 2500.';
    } else if (s.contains('usn')) {
      r = 'Your USN is ${widget.student.usn}.';
    }
    setState(() {
      user = q;
      reply = r;
      page = p;
    });
  }

  @override
  Widget build(BuildContext context) {
    final screens = [
      HomePage(open: open),
      ProfilePage(student: widget.student),
      RegistrationPage(student: widget.student),
      const PaymentPage(),
      ResultPage(student: widget.student, filter: resultFilter),
      CertificatePage(student: widget.student),
      DashboardPage(student: widget.student),
      const AttendancePage(),
      HallTicketPage(student: widget.student),
    ];
    return Scaffold(
      backgroundColor: Clr.page,
      body: Stack(
        children: [
          Column(
            children: [
              Header(
                student: widget.student,
                current: page,
                open: open,
                logout: () => Navigator.pushReplacement(
                  context,
                  MaterialPageRoute(builder: (_) => const LoginPage()),
                ),
              ),
              if (widget.demo)
                Container(
                  width: double.infinity,
                  color: const Color(0xFFFFF7DF),
                  padding: const EdgeInsets.all(8),
                  child: const Text(
                    'Demo mode: backend API not reachable. Sample ERP data is shown.',
                    textAlign: TextAlign.center,
                  ),
                ),
              Expanded(child: screens[page]),
            ],
          ),
          if (bot)
            Positioned(
              right: 14,
              bottom: 86,
              child: VapiVoiceBox(
                lang: lang,
                user: user,
                reply: reply,
                onClose: () => setState(() => bot = false),
                onLang: (v) => setState(() {
                  lang = v;
                  reply = '$v selected. Ask your ERP question.';
                }),
                onAsk: query,
                onNavigate: _applyVoiceNavigation,
              ),
            ),
          Positioned(
            right: 18,
            bottom: 20,
            child: FloatingActionButton.extended(
              backgroundColor: Clr.maroon,
              foregroundColor: Colors.white,
              onPressed: () => setState(() => bot = true),
              icon: const Icon(Icons.mic),
              label: const Text('Tap to ask'),
            ),
          ),
        ],
      ),
    );
  }

  String _applyVoiceNavigation(String path, String pageName, [Map<String, dynamic>? resultRequest]) {
    final target = _pageIndexForPath(path, pageName);
    final label = _spokenActionPageName(pageName, target);
    if (target == null) return 'I could not open that page.';
    if (page == target && resultRequest == null) return 'You are already on the ${label.toLowerCase()} page.';
    setState(() {
      page = target;
      reply = '$label page opened.';
      if (resultRequest != null && target == 4) {
        resultFilter = {
          'semester': '${resultRequest['semester'] ?? ''}',
          'examType': '${resultRequest['examType'] ?? 'SEE'}',
          'year':     '${resultRequest['year'] ?? ''}',
          'season':   '${resultRequest['season'] ?? ''}',
          'usn':      '${resultRequest['usn'] ?? ''}',
        };
      }
    });
    return '$label page opened.';
  }

  int? _pageIndexForPath(String path, String pageName) {
    final pageKey = pageName.toLowerCase().trim();
    final pathKey = path.split('?').first.toLowerCase().trim();
    const byPage = {
      'home': 0,
      'profile': 1,
      'registration': 2,
      're_registration': 2,
      'resit': 2,
      'apply_exam': 2,
      'payment': 3,
      'results': 4,
      'result': 4,
      'certificate': 5,
      'dashboard': 6,
      'attendance': 7,
      'hallticket': 8,
      'hall_ticket': 8,
      'hall ticket': 8,
    };
    const byPath = {
      '/home': 0,
      '/profile': 1,
      '/registration': 2,
      '/re-registration': 2,
      '/resit': 2,
      '/apply-exam': 2,
      '/payment': 3,
      '/results': 4,
      '/certificate': 5,
      '/dashboard': 6,
      '/attendance': 7,
      '/attendance-analytics': 7,
      '/hall-ticket': 8,
    };
    return byPath[pathKey] ?? byPage[pageKey];
  }

  String _spokenPageName(int index) {
    const names = {
      0: 'Home',
      1: 'Profile',
      2: 'Registration',
      3: 'Payment',
      4: 'Result',
      5: 'Certificate',
      6: 'Dashboard',
      7: 'Attendance',
      8: 'Hall Ticket',
    };
    return names[index] ?? 'ERP';
  }

  String _spokenActionPageName(String pageName, int? index) {
    final key = pageName.toLowerCase().trim();
    const actionNames = {
      'results': 'Result',
      'result': 'Result',
      'hallticket': 'Hall ticket',
      'hall_ticket': 'Hall ticket',
      'hall ticket': 'Hall ticket',
      're_registration': 'Re-Registration',
      'resit': 'Apply Resit',
      'apply_exam': 'Apply Exam',
    };
    return actionNames[key] ?? (index == null ? 'ERP' : _spokenPageName(index));
  }
}

class Header extends StatelessWidget {
  const Header({
    super.key,
    required this.student,
    required this.current,
    required this.open,
    required this.logout,
  });
  final Student student;
  final int current;
  final void Function(int, [String?]) open;
  final VoidCallback logout;

  @override
  Widget build(BuildContext context) {
    final compact = MediaQuery.of(context).size.width < 760;
    final nav = List.generate(
      _ErpShellState.titles.length,
      (i) => Padding(
        padding: const EdgeInsets.only(right: 12),
        child: TextButton(
          onPressed: () => open(i),
          child: Text(
            _ErpShellState.titles[i],
            style: TextStyle(
              color: Colors.white,
              fontWeight: FontWeight.w900,
              decoration: current == i ? TextDecoration.underline : null,
            ),
          ),
        ),
      ),
    );
    return Container(
      color: Clr.maroon,
      padding: const EdgeInsets.fromLTRB(18, 16, 18, 16),
      child: SafeArea(
        bottom: false,
        child: compact
            ? Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      const Expanded(
                        child: Text(
                          'GMU-ERP',
                          style: TextStyle(
                            color: Colors.white,
                            fontSize: 30,
                            fontWeight: FontWeight.w900,
                          ),
                        ),
                      ),
                      UserChip(student: student, logout: logout),
                    ],
                  ),
                  const SizedBox(height: 12),
                  SingleChildScrollView(
                    scrollDirection: Axis.horizontal,
                    child: Row(children: nav),
                  ),
                ],
              )
            : Row(
                children: [
                  const SizedBox(
                    width: 145,
                    child: Text(
                      'GMU-\nERP',
                      style: TextStyle(
                        color: Colors.white,
                        fontSize: 38,
                        height: 1.05,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                  ),
                  Expanded(
                    child: Wrap(
                      alignment: WrapAlignment.spaceEvenly,
                      children: nav,
                    ),
                  ),
                  UserChip(student: student, logout: logout),
                ],
              ),
      ),
    );
  }
}

class UserChip extends StatelessWidget {
  const UserChip({super.key, required this.student, required this.logout});
  final Student student;
  final VoidCallback logout;
  @override
  Widget build(BuildContext context) => PopupMenuButton<String>(
    onSelected: (_) => logout(),
    itemBuilder: (_) => const [
      PopupMenuItem(value: 'logout', child: Text('Logout')),
    ],
    child: Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.person, color: Clr.maroon),
          const SizedBox(width: 8),
          Text(
            student.name,
            style: const TextStyle(fontWeight: FontWeight.w900),
          ),
          const Icon(Icons.arrow_drop_down),
        ],
      ),
    ),
  );
}

class HomePage extends StatelessWidget {
  const HomePage({super.key, required this.open});
  final void Function(int, [String?]) open;

  @override
  Widget build(BuildContext context) {
    final items = [
      ('HALL-TICKET', Icons.confirmation_number, -1),
      ('PROFILE', Icons.person, 1),
      ('REGISTRATION', Icons.assignment, 2),
      ('PAYMENT PORTAL', Icons.payments, 3),
      ('RESULT - SEE', Icons.school, 4),
      ('STUDENT DASHBOARD', Icons.dashboard, 6),
      ('ATTENDANCE', Icons.bar_chart, 7),
      ('DIGITAL COMPETENCY CERTIFICATE', Icons.workspace_premium, 5),
      ('ASSESSMENT SCANS', Icons.document_scanner, -1),
    ];
    return SingleChildScrollView(
      padding: const EdgeInsets.all(24),
      child: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 1120),
          child: GridView.builder(
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            itemCount: items.length,
            gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
              crossAxisCount: MediaQuery.of(context).size.width < 720 ? 1 : 3,
              mainAxisSpacing: 20,
              crossAxisSpacing: 20,
              childAspectRatio: MediaQuery.of(context).size.width < 720
                  ? 3.0
                  : 2.5,
            ),
            itemBuilder: (_, i) => MenuCard(
              title: items[i].$1,
              icon: items[i].$2,
              onTap: items[i].$3 < 0 ? null : () => open(items[i].$3),
            ),
          ),
        ),
      ),
    );
  }
}

class MenuCard extends StatelessWidget {
  const MenuCard({
    super.key,
    required this.title,
    required this.icon,
    this.onTap,
  });
  final String title;
  final IconData icon;
  final VoidCallback? onTap;
  @override
  Widget build(BuildContext context) => Material(
    color: Clr.cream,
    borderRadius: BorderRadius.circular(8),
    child: InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(8),
      child: Container(
        decoration: BoxDecoration(
          border: Border.all(color: Clr.maroon, width: 2.4),
          borderRadius: BorderRadius.circular(8),
        ),
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        child: LayoutBuilder(
          builder: (context, constraints) {
            final compact = constraints.maxHeight < 115;
            return Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(icon, color: Clr.maroon, size: compact ? 24 : 30),
                SizedBox(height: compact ? 6 : 10),
                Flexible(
                  child: FittedBox(
                    fit: BoxFit.scaleDown,
                    child: Text(
                      title,
                      textAlign: TextAlign.center,
                      maxLines: 2,
                      style: const TextStyle(
                        color: Clr.maroon,
                        fontSize: 18,
                        fontWeight: FontWeight.w900,
                        decoration: TextDecoration.underline,
                      ),
                    ),
                  ),
                ),
              ],
            );
          },
        ),
      ),
    ),
  );
}

class ProfilePage extends StatelessWidget {
  const ProfilePage({super.key, required this.student});
  final Student student;
  @override
  Widget build(BuildContext context) => PageCard(
    title: 'Profile',
    child: InfoGrid(
      rows: [
        ('User Name', student.aadhaar),
        ('Name', student.name),
        ('USN', student.usn),
        ('Mobile No', student.mobile),
        ('Email', student.email),
        ('Program', student.branch),
      ],
    ),
  );
}

class RegistrationPage extends StatefulWidget {
  const RegistrationPage({super.key, required this.student});
  final Student student;
  @override
  State<RegistrationPage> createState() => _RegistrationPageState();
}

class _RegistrationPageState extends State<RegistrationPage> {
  var courses = <List<String>>[
    ['CS501', 'Database Management Systems', '4', 'Theory'],
    ['CS502', 'Operating Systems', '4', 'Theory'],
    ['CS503', 'Computer Networks', '3', 'Theory'],
  ];
  var payments = <List<String>>[
    ['Tuition Fee', 'Rs. 85000', 'Rs. 50000', 'Rs. 35000'],
    ['Skill Fee', 'Rs. 12000', 'Rs. 12000', 'Rs. 0'],
    ['Other Fee', 'Rs. 3500', 'Rs. 1000', 'Rs. 2500'],
  ];
  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load() async {
    try {
      final c = await api.get('getCourses.php');
      final p = await api.get('getPaymentDetails.php');
      if (!mounted) return;
      setState(() {
        if (c is List) {
          courses = c
              .map<List<String>>(
                (e) => [
                  '${e['code'] ?? e['course_code'] ?? ''}',
                  '${e['title'] ?? e['course_title'] ?? ''}',
                  '${e['credits'] ?? e['group'] ?? ''}',
                  '${e['type'] ?? ''}',
                ],
              )
              .toList();
        }
        if (p is List) {
          payments = p
              .map<List<String>>(
                (e) => [
                  '${e['fee_type'] ?? ''}',
                  'Rs. ${e['total_fee'] ?? ''}',
                  'Rs. ${e['paid'] ?? ''}',
                  'Rs. ${e['balance'] ?? ''}',
                ],
              )
              .toList();
        }
      });
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) => PageCard(
    title: 'Course Registration',
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Status(text: 'Final Registration Fees Payment is Pending'),
        const SizedBox(height: 16),
        InfoGrid(
          rows: [
            ('Name', widget.student.name),
            ('USN', widget.student.usn),
            ('Branch', widget.student.branch),
            ('Email', widget.student.email),
          ],
        ),
        const SizedBox(height: 22),
        DataBlock(
          title: 'Course Details',
          cols: const ['Code', 'Course', 'Credits', 'Type'],
          rows: courses,
        ),
        const SizedBox(height: 22),
        DataBlock(
          title: 'Payment Details',
          cols: const ['Fee Type', 'Total Fee', 'Paid', 'Balance'],
          rows: payments,
        ),
      ],
    ),
  );
}

class PaymentPage extends StatelessWidget {
  const PaymentPage({super.key});

  @override
  Widget build(BuildContext context) {
    final actions = [
      (
        'College / Tuition Fee',
        'Registration > Payment > College/Tuition Fee',
        Icons.account_balance,
      ),
      ('Hostel Fee', 'Registration > Payment > Hostel Fee', Icons.apartment),
      (
        'Skill / Late / Other Fee',
        'Registration > Payment > Skill/Late-Registration/Other Fee',
        Icons.category,
      ),
      (
        'Download Receipt',
        'Registration > Payment > Download Receipt',
        Icons.receipt_long,
      ),
      (
        'Payment Grievance',
        'Registration > Payment > Payment Grievance',
        Icons.report_problem,
      ),
      (
        'Grievance Result',
        'Registration > Payment > Grievance Result',
        Icons.fact_check,
      ),
    ];
    return PageCard(
      title: 'Payment Portal',
      child: Wrap(
        spacing: 16,
        runSpacing: 16,
        children: actions
            .map(
              (a) => SizedBox(
                width: MediaQuery.of(context).size.width < 720
                    ? double.infinity
                    : 320,
                child: ActionCard(title: a.$1, text: a.$2, icon: a.$3),
              ),
            )
            .toList(),
      ),
    );
  }
}

class ResultPage extends StatelessWidget {
  const ResultPage({super.key, required this.student, this.filter = const {}});
  final Student student;
  // Populated by voice navigation: semester, examType, year, season, usn
  final Map<String, String> filter;

  @override
  Widget build(BuildContext context) {
    final semester = filter['semester']?.isNotEmpty == true ? filter['semester']! : student.sem;
    final examType = filter['examType']?.isNotEmpty == true ? filter['examType']! : 'SEE';
    final year     = filter['year']?.isNotEmpty == true     ? filter['year']!     : '2025-26';
    final season   = filter['season']?.isNotEmpty == true   ? filter['season']!   : '';
    final examLabel = season.isNotEmpty ? '$examType $season' : examType;

    return PageCard(
      title: 'Grade Sheet — $examLabel',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Shows which result is being viewed — matches the real ERP Grade Sheet Generator fields
          Container(
            padding: const EdgeInsets.all(10),
            margin: const EdgeInsets.only(bottom: 16),
            decoration: BoxDecoration(
              color: Clr.cream,
              borderRadius: BorderRadius.circular(8),
              border: Border.all(color: Clr.gold),
            ),
            child: Row(
              children: [
                const Icon(Icons.info_outline, size: 16, color: Clr.maroon),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    'Semester $semester  •  $examType  •  $year${season.isNotEmpty ? "  •  $season" : ""}',
                    style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: Clr.maroon),
                  ),
                ),
              ],
            ),
          ),
          InfoGrid(
            rows: [
              ('Name', student.name),
              ('USN', student.usn),
              ('Program', student.branch),
              ('Semester', semester),
              ('Academic Year', year),
              ('Exam', examLabel),
            ],
          ),
          const SizedBox(height: 22),
          DataBlock(
            title: 'Result Details',
            cols: const ['Code', 'Course Title', 'Credits', 'Grade', 'Point'],
            rows: const [
              ['CS501', 'Database Management Systems', '4.00', 'A+', '9'],
              ['CS502', 'Operating Systems', '4.00', 'A', '8'],
              ['CS503', 'Computer Networks', '3.00', 'A', '8'],
              ['CS5L1', 'DBMS Laboratory', '1.50', 'A+', '9'],
            ],
          ),
          const SizedBox(height: 18),
          const Wrap(
            spacing: 12,
            children: [
              Metric(label: 'Credits', value: '15.5'),
              Metric(label: 'SGPA', value: '8.55'),
              Metric(label: 'Status', value: 'PASS'),
            ],
          ),
        ],
      ),
    );
  }
}

class CertificatePage extends StatelessWidget {
  const CertificatePage({super.key, required this.student});
  final Student student;
  @override
  Widget build(BuildContext context) => PageCard(
    title: 'Digital Competency Certificate',
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        DataBlock(
          title: 'Earned Certificates',
          cols: const ['Year', 'Sem', 'Code', 'Subject', 'Grade', 'Date'],
          rows: const [
            [
              '2024-25',
              '1',
              'HG24TCCYS1',
              'Fundamentals of Cyber Security',
              'O',
              '12-06-2025',
            ],
            [
              '2024-25',
              '2',
              'HG24TCCYS2',
              'Cybersecurity Essentials',
              'A+',
              '24-10-2025',
            ],
            [
              '2025-26',
              '3',
              'HG24TCESIE',
              'Technical Skills',
              'O',
              '11-04-2026',
            ],
          ],
        ),
        const SizedBox(height: 18),
        ActionCard(
          title: 'Certificate Preview',
          text:
              '${student.name} (${student.usn}) has completed the competency activities listed above.',
          icon: Icons.workspace_premium,
        ),
      ],
    ),
  );
}

class DashboardPage extends StatelessWidget {
  const DashboardPage({super.key, required this.student});
  final Student student;
  @override
  Widget build(BuildContext context) => PageCard(
    title: 'Student Dashboard',
    child: Column(
      children: [
        const Wrap(
          spacing: 14,
          runSpacing: 14,
          children: [
            Metric(label: 'Attendance', value: '83.13%'),
            Metric(label: 'CGPA', value: '6.45'),
            Metric(label: 'Latest SGPA', value: '8.55'),
            Metric(label: 'Fee Balance', value: 'Rs. 37500'),
          ],
        ),
        const SizedBox(height: 22),
        InfoGrid(
          rows: [
            ('Name', student.name),
            ('USN', student.usn),
            ('Discipline', student.branch),
            ('Email', student.email),
          ],
        ),
      ],
    ),
  );
}

class AttendancePage extends StatelessWidget {
  const AttendancePage({super.key});

  @override
  Widget build(BuildContext context) => PageCard(
    title: 'Attendance',
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: const [
        Metric(label: 'Overall Attendance', value: '83.13%'),
        SizedBox(height: 14),
        InfoGrid(
          rows: [
            ('DBMS', '86%'),
            ('Operating Systems', '82%'),
            ('Computer Networks', '81%'),
          ],
        ),
      ],
    ),
  );
}

class HallTicketPage extends StatelessWidget {
  const HallTicketPage({super.key, required this.student});
  final Student student;

  @override
  Widget build(BuildContext context) => PageCard(
    title: 'Hall Ticket',
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text(
          'Admission Ticket Generator',
          style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: Clr.maroon),
        ),
        const SizedBox(height: 16),
        InfoGrid(
          rows: [
            ('USN', student.usn),
            ('Exam Types', 'SEE  •  RESIT  •  RE-REGISTRATION'),
          ],
        ),
        const SizedBox(height: 20),
        Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: Clr.cream,
            borderRadius: BorderRadius.circular(10),
            border: Border.all(color: Clr.gold),
          ),
          child: const Text(
            'To generate your hall ticket, log in to GMU-ERP, go to Student Hallticket, enter your USN and select the exam type (SEE / RESIT / RE-REGISTRATION).',
            style: TextStyle(fontSize: 14, height: 1.5),
          ),
        ),
        const SizedBox(height: 16),
        const Text(
          'Ask the VoiceBot for your hall ticket status by saying:\n"What is my SEE hall ticket status?" or "Check my RESIT hall ticket."',
          style: TextStyle(fontSize: 13, color: Colors.black54, height: 1.5),
        ),
      ],
    ),
  );
}

// -----------------------------------------------------------------------------
//  VoiceBox  —  single-tap conversation mode
//  � Opens ? starts listening immediately (no extra tap needed)
//  � STT result ? sends to backend ? TTS speaks reply ? listens again
//  � Silence for 60 s with no words ? closes automatically
//  � TTS finishes completely before mic opens again (no self-echo)
// -----------------------------------------------------------------------------

class VapiVoiceBox extends StatefulWidget {
  const VapiVoiceBox({
    super.key,
    required this.lang,
    required this.user,
    required this.reply,
    required this.onClose,
    required this.onLang,
    required this.onAsk,
    required this.onNavigate,
  });

  final String lang;
  final String user;
  final String reply;
  final VoidCallback onClose;
  final ValueChanged<String> onLang;
  final ValueChanged<String> onAsk;
  final String Function(String path, String page, [Map<String, dynamic>? resultRequest]) onNavigate;

  @override
  State<VapiVoiceBox> createState() => _VapiVoiceBoxState();
}

class _VapiVoiceBoxState extends State<VapiVoiceBox> {
  final _textCtrl = TextEditingController();
  bool _connecting = false;
  bool _callActive = false;
  bool _speaking = false;
  String _status = 'Tap mic to start Vapi';
  String _source = 'vapi';
  String _localLang = 'English';
  String _localUser = '';
  String _localReply = '';
  String? _error;
  String _sessionToken = '';
  Map<String, dynamic>? _visual;
  final Set<String> _handledActionIds = <String>{};
  int _lastNavigationMs = 0;
  VapiClient? _vapiClient;
  VapiCall? _vapiCall;
  static const _audioChannel = MethodChannel('gmu.voicebot/audio');

  Future<void> _setSpeakerphone() async {
    if (kIsWeb) return;
    try { await _audioChannel.invokeMethod('setSpeakerphone'); } catch (_) {}
  }

  Future<void> _resetAudio() async {
    if (kIsWeb) return;
    try { await _audioChannel.invokeMethod('resetAudio'); } catch (_) {}
  }

  @override
  void initState() {
    super.initState();
    _localLang = widget.lang;
    _localUser = widget.user;
    _localReply = widget.reply;
  }

  @override
  void didUpdateWidget(covariant VapiVoiceBox oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.lang != widget.lang) _localLang = widget.lang;
    if (oldWidget.user != widget.user) _localUser = widget.user;
    if (oldWidget.reply != widget.reply) _localReply = widget.reply;
  }

  @override
  void dispose() {
    _textCtrl.dispose();
    _vapiCall?.stop();
    _vapiCall?.dispose();
    _vapiClient?.dispose();
    super.dispose();
  }

  String get _languageCode {
    final value = _localLang.toLowerCase();
    if (value.startsWith('hin')) return 'hi';
    if (value.startsWith('kan')) return 'kn';
    return 'en';
  }

  Future<void> _startVapi() async {
    if (_connecting || _callActive) return;

    // Request microphone permission on Android/iOS
    if (!kIsWeb) {
      final status = await Permission.microphone.request();
      if (!status.isGranted) {
        setState(() => _error = 'Microphone permission denied. Please allow microphone access in Settings.');
        return;
      }
    }

    setState(() {
      _connecting = true;
      _error = null;
      _status = 'Starting Vapi...';
      _source = 'vapi';
    });

    try {
      final config = await api.get('vapiConfig.php?language=$_languageCode');
      if (config is! Map || config['enabled'] != true) {
        throw Exception(config is Map ? (config['error'] ?? config['setup_hint'] ?? 'Vapi is not configured.') : 'Invalid Vapi config.');
      }
      _sessionToken = '${config['session_token'] ?? ''}';

      final publicKey = '${config['public_key'] ?? ''}';
      _vapiClient = VapiClient(publicKey);

      final assistantId = config['assistant_id'] as String?;
      final overrides = (config['assistant_overrides'] as Map?)?.cast<String, dynamic>() ?? {};
      _vapiCall = await _vapiClient!.start(
        assistantId: assistantId,
        assistantOverrides: overrides,
      );
      _vapiCall!.onEvent.listen(_onVapiFlutterEvent);

      if (!mounted) return;
      setState(() {
        _connecting = false;
        _status = 'Connecting...';
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _connecting = false;
        _callActive = false;
        _status = 'Vapi unavailable';
        _error = '$error';
        _localReply = 'I could not start Vapi. Please check Vapi config and microphone permission.';
      });
    }
  }

  void _onVapiFlutterEvent(VapiEvent event) {
    final Map<String, dynamic> eventMap = {'type': event.label};
    if (event.value != null) {
      if (event.label == 'message') {
        eventMap['message'] = event.value;
      } else if (event.label == 'error' || event.label == 'call-error') {
        eventMap['type'] = 'error';
        eventMap['message'] = event.value.toString();
      }
    }
    _handleVapiEvent(jsonEncode(eventMap));
  }

  void _stopVapi() {
    _vapiCall?.stop();
    _vapiCall?.dispose();
    _vapiCall = null;
    _vapiClient?.dispose();
    _vapiClient = null;
    setState(() {
      _callActive = false;
      _speaking = false;
      _connecting = false;
      _status = 'Stopped';
    });
  }

  void _handleVapiEvent(String eventJson) {
    if (!mounted) return;
    try {
      final event = jsonDecode(eventJson);
      if (event is! Map) return;
      final type = '${event['type'] ?? ''}';
      if (type == 'call-start') {
        _setSpeakerphone();
        setState(() {
          _callActive = true;
          _connecting = false;
          _status = 'Listening with Vapi...';
        });
        return;
      }
      if (type == 'call-end') {
        _resetAudio();
        setState(() {
          _callActive = false;
          _speaking = false;
          _status = 'Tap mic to start Vapi';
        });
        return;
      }
      if (type == 'speech-start') {
        // Re-assert speaker routing every time bot starts talking —
        // WebRTC (Daily.co) finishes audio init after call-start and can
        // override our earlier setCommunicationDevice call.
        _setSpeakerphone();
        setState(() {
          _speaking = true;
          _status = 'Speaking...';
        });
        return;
      }
      if (type == 'speech-end') {
        setState(() {
          _speaking = false;
          _status = _callActive ? 'Listening with Vapi...' : 'Tap mic to start Vapi';
        });
        return;
      }
      if (type == 'error') {
        setState(() {
          _error = '${event['message'] ?? 'Vapi voice session failed.'}';
          _status = 'Vapi error';
          _callActive = false;
          _connecting = false;
        });
        return;
      }
      if (type == 'message') {
        _handleVapiMessage(event['message']);
      }
    } catch (error) {
      setState(() => _error = '$error');
    }
  }

  void _handleVapiMessage(dynamic message) {
    if (message is! Map) return;
    final msg = Map<String, dynamic>.from(message);
    final msgType = '${msg['type'] ?? ''}';
    final transcript = '${msg['transcript'] ?? msg['text'] ?? ''}'.trim();
    final role = '${msg['role'] ?? ''}'.toLowerCase();

    if (msgType == 'transcript' && transcript.isNotEmpty) {
      setState(() {
        if (role == 'assistant') {
          _localReply = transcript;
        } else {
          _localUser = transcript;
          // Clear the previous answer immediately so the UI doesn't show
          // a stale reply while the new question is being processed.
          _localReply = '';
          _visual = null;
          _status = 'Thinking...';
        }
      });
    }

    final result = _findToolResult(msg);
    if (result != null) _applyToolResult(result);
  }

  Map<String, dynamic>? _findToolResult(dynamic value) {
    if (value is List) {
      for (final item in value) {
        final found = _findToolResult(item);
        if (found != null) return found;
      }
      return null;
    }
    if (value is! Map) return null;
    final map = Map<String, dynamic>.from(value);
    if (map['reply'] != null || map['client_action'] != null || map['quick_actions'] != null || map['visual'] != null) {
      return map;
    }
    final rawResult = map['result'];
    if (rawResult is String && rawResult.trim().isNotEmpty) {
      try {
        final decoded = jsonDecode(rawResult);
        final found = _findToolResult(decoded);
        if (found != null) return found;
      } catch (_) {}
    } else if (rawResult is Map || rawResult is List) {
      final found = _findToolResult(rawResult);
      if (found != null) return found;
    }
    for (final item in map.values) {
      final found = _findToolResult(item);
      if (found != null) return found;
    }
    return null;
  }

  void _applyToolResult(Map<String, dynamic> result) {
    final reply = '${result['reply'] ?? ''}'.trim();
    final source = '${result['source'] ?? result['debug']?['source'] ?? 'vapi'}'.trim();
    final visual = result['visual'] is Map ? Map<String, dynamic>.from(result['visual'] as Map) : null;
    final action = result['client_action'] is Map
        ? Map<String, dynamic>.from(result['client_action'] as Map)
        : (result['clientAction'] is Map ? Map<String, dynamic>.from(result['clientAction'] as Map) : null);

    final actionReply = _applyClientAction(result, action);
    if (actionReply.isEmpty && _isRecentNavigationFallback(result)) {
      return;
    }

    setState(() {
      if (actionReply.isNotEmpty) {
        _localReply = actionReply;
      } else if (reply.isNotEmpty) {
        _localReply = reply;
      }
      _visual = visual;
      _source = source.isEmpty ? 'vapi' : source;
      _status = _callActive ? 'Listening with Vapi...' : 'Ready';
    });
  }

  String _applyClientAction(Map<String, dynamic> result, Map<String, dynamic>? action) {
    if (action == null) return '';
    final actionId = _actionTrackingId(result, action);
    if (actionId.isNotEmpty && _handledActionIds.contains(actionId)) {
      return '';
    }
    if (actionId.isNotEmpty) {
      _handledActionIds.add(actionId);
      if (_handledActionIds.length > 40) _handledActionIds.remove(_handledActionIds.first);
    }

    if (action['type'] == 'navigate') {
      final resultRequest = result['client_action']?['result_request'] is Map
          ? Map<String, dynamic>.from(result['client_action']['result_request'] as Map)
          : (action['result_request'] is Map
              ? Map<String, dynamic>.from(action['result_request'] as Map)
              : null);
      final confirmation = _applyNavigation('${action['path'] ?? ''}', '${action['page'] ?? ''}', resultRequest);
      if (confirmation.isNotEmpty && !confirmation.startsWith('I could not')) {
        _lastNavigationMs = DateTime.now().millisecondsSinceEpoch;
      }
      return confirmation;
    }
    if (action['type'] == 'set_language') {
      final nextLanguage = _languageName('${action['language'] ?? ''}');
      if (nextLanguage.isNotEmpty) {
        _localLang = nextLanguage;
        widget.onLang(nextLanguage);
      }
    }
    return '';
  }

  bool _isRecentNavigationFallback(Map<String, dynamic> result) {
    final elapsed = DateTime.now().millisecondsSinceEpoch - _lastNavigationMs;
    if (_lastNavigationMs <= 0 || elapsed > 1500) return false;
    final route = '${result['route'] ?? ''}'.toLowerCase();
    final intent = '${result['intent'] ?? ''}'.toUpperCase();
    return route == 'fallback' || route == 'llm' || intent == 'UNKNOWN';
  }

  String _actionTrackingId(Map<String, dynamic> result, Map<String, dynamic> action) {
    final debug = result['debug'] is Map ? Map<String, dynamic>.from(result['debug'] as Map) : const <String, dynamic>{};
    final parts = [
      result['tool_execution_id'],
      result['request_id'],
      result['call_id'],
      debug['tool_execution_id'],
      debug['request_id'],
      debug['call_id'],
      action['type'],
      action['path'],
      action['page'],
    ].map((value) => '$value'.trim()).where((value) => value.isNotEmpty && value != 'null').toList();
    return parts.join('|');
  }

  Future<void> _sendTypedMessage() async {
    final text = _textCtrl.text.trim();
    if (text.isEmpty) return;
    setState(() {
      _localUser = text;
      _status = 'Sending to Vapi backend...';
      _error = null;
    });
    try {
      if (_sessionToken.isEmpty) {
        final config = await api.get('vapiConfig.php?language=$_languageCode');
        if (config is Map) _sessionToken = '${config['session_token'] ?? ''}';
      }
      final response = await api.post('vapiWebhook.php', {
        'message': {
          'type': 'tool-calls',
          'toolCalls': [
            {
              'id': 'flutter-typed-${DateTime.now().millisecondsSinceEpoch}',
              'function': {
                'name': 'gmu_voice_assistant',
                'arguments': jsonEncode({
                  'query': text,
                  'language': _languageCode,
                  'session_token': _sessionToken,
                }),
              },
            }
          ],
          'call': {
            'assistantOverrides': {
              'variableValues': {
                'session_token': _sessionToken,
                'student_session_token': _sessionToken,
                'voice_language': _languageCode,
              },
            },
          },
        },
      });
      final result = _findToolResult(response);
      if (result != null) _applyToolResult(result);
      _textCtrl.clear();
    } catch (error) {
      setState(() {
        _error = '$error';
        _status = 'Backend unavailable';
      });
    }
  }

  String _applyNavigation(String path, String page, [Map<String, dynamic>? resultRequest]) {
    return widget.onNavigate(path, page, resultRequest);
  }

  String _languageName(String code) {
    switch (code.toLowerCase()) {
      case 'en':
      case 'english':
        return 'English';
      case 'hi':
      case 'hindi':
        return 'Hindi';
      case 'kn':
      case 'kannada':
        return 'Kannada';
      default:
        return '';
    }
  }

  Future<void> _selectLanguage(String lang) async {
    if (_localLang == lang) return;
    setState(() {
      _localLang = lang;
      _localReply = '$lang selected. Restart Vapi to use this language.';
    });
    widget.onLang(lang);
    if (_callActive) {
      _stopVapi();
      await _startVapi();
    }
  }

  Future<void> _close() async {
    _stopVapi();
    widget.onClose();
  }

  @override
  Widget build(BuildContext context) {
    final width = MediaQuery.sizeOf(context).width;
    final panelWidth = width < 720 ? width - 28 : 520.0;
    final displayUser = _localUser.trim().isNotEmpty ? _localUser : widget.user;
    final displayReply = _localReply.trim().isNotEmpty ? _localReply : widget.reply;

    return Material(
      elevation: 18,
      borderRadius: BorderRadius.circular(16),
      child: Container(
        width: panelWidth,
        constraints: const BoxConstraints(maxHeight: 620),
        decoration: BoxDecoration(
          color: Colors.white,
          border: Border.all(color: Clr.maroon, width: 3),
          borderRadius: BorderRadius.circular(16),
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              height: 64,
              padding: const EdgeInsets.symmetric(horizontal: 18),
              decoration: const BoxDecoration(
                color: Clr.maroon,
                borderRadius: BorderRadius.vertical(top: Radius.circular(12)),
              ),
              child: Row(
                children: [
                  const Expanded(
                    child: Text(
                      'GMU Vapi VoiceBot',
                      textAlign: TextAlign.center,
                      style: TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w800),
                    ),
                  ),
                  IconButton(onPressed: _close, icon: const Icon(Icons.close, color: Colors.white)),
                ],
              ),
            ),
            Flexible(
              child: SingleChildScrollView(
                padding: const EdgeInsets.all(20),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Wrap(
                      spacing: 12,
                      runSpacing: 10,
                      children: ['English', 'Hindi', 'Kannada'].map((lang) {
                        final selected = lang == _localLang;
                        return SizedBox(
                          width: panelWidth < 420 ? panelWidth - 46 : 132,
                          child: OutlinedButton.icon(
                            onPressed: () => _selectLanguage(lang),
                            icon: selected ? const Icon(Icons.check, size: 18) : const SizedBox.shrink(),
                            label: Text(lang),
                            style: OutlinedButton.styleFrom(
                              backgroundColor: selected ? Clr.maroon : Colors.white,
                              foregroundColor: selected ? Colors.white : Clr.maroon,
                              side: const BorderSide(color: Clr.maroon),
                              padding: const EdgeInsets.symmetric(vertical: 14),
                            ),
                          ),
                        );
                      }).toList(),
                    ),
                    const SizedBox(height: 22),
                    Row(
                      children: [
                        Container(
                          width: 12,
                          height: 12,
                          decoration: BoxDecoration(
                            color: _callActive ? Colors.green : (_connecting ? Colors.orange : Colors.grey),
                            shape: BoxShape.circle,
                          ),
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Text.rich(
                            TextSpan(
                              children: [
                                const TextSpan(text: 'Status: ', style: TextStyle(fontWeight: FontWeight.w800, color: Clr.maroon)),
                                TextSpan(text: _status),
                              ],
                            ),
                            style: const TextStyle(fontSize: 16),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 12),
                    Text.rich(
                      TextSpan(
                        children: [
                          const TextSpan(text: 'Language: ', style: TextStyle(fontWeight: FontWeight.w800, color: Clr.maroon)),
                          TextSpan(text: _localLang),
                        ],
                      ),
                      style: const TextStyle(fontSize: 16),
                    ),
                    const SizedBox(height: 18),
                    _Line(label: 'You', text: displayUser),
                    const SizedBox(height: 14),
                    _Line(label: 'Assistant', text: displayReply),
                    if (_visual != null) ...[
                      const SizedBox(height: 16),
                      VisualRenderer(visual: _visual!),
                    ],
                    const SizedBox(height: 14),
                    _Line(label: 'Source', text: _source),
                    if (_speaking) ...[
                      const SizedBox(height: 12),
                      const _Line(label: 'Voice', text: 'Vapi is speaking...'),
                    ],
                    if (_error != null) ...[
                      const SizedBox(height: 16),
                      Container(
                        width: double.infinity,
                        padding: const EdgeInsets.all(14),
                        decoration: BoxDecoration(
                          color: const Color(0xFFFFE3E3),
                          borderRadius: BorderRadius.circular(8),
                          border: const Border(left: BorderSide(color: Colors.red, width: 5)),
                        ),
                        child: Text(_error!, style: const TextStyle(color: Colors.red, height: 1.35)),
                      ),
                    ],
                    const SizedBox(height: 20),
                    Row(
                      children: [
                        Expanded(
                          child: TextField(
                            controller: _textCtrl,
                            textInputAction: TextInputAction.send,
                            onSubmitted: (_) => _sendTypedMessage(),
                            decoration: const InputDecoration(hintText: 'Type here or use Vapi voice', border: OutlineInputBorder()),
                          ),
                        ),
                        const SizedBox(width: 10),
                        IconButton.filled(
                          onPressed: _connecting ? null : (_callActive ? _stopVapi : _startVapi),
                          icon: Icon(_callActive ? Icons.stop : Icons.mic),
                          style: IconButton.styleFrom(
                            backgroundColor: _callActive ? Colors.green : Clr.maroon,
                            foregroundColor: Colors.white,
                            padding: const EdgeInsets.all(16),
                          ),
                        ),
                        const SizedBox(width: 8),
                        IconButton.filled(
                          onPressed: _sendTypedMessage,
                          icon: const Icon(Icons.send),
                          style: IconButton.styleFrom(backgroundColor: Clr.maroon, foregroundColor: Colors.white, padding: const EdgeInsets.all(16)),
                        ),
                      ],
                    ),
                    const SizedBox(height: 10),
                    Text(
                      _callActive
                          ? 'Vapi is connected. Speak naturally.'
                          : 'Uses Vapi Web SDK with your existing Vapi assistant config.',
                      style: TextStyle(color: Colors.grey.shade700),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class VisualRenderer extends StatelessWidget {
  const VisualRenderer({super.key, required this.visual});

  final Map<String, dynamic> visual;

  static const _maroon = Color(0xFF6F1D1B);
  static const _gold = Color(0xFFD8B85A);
  static const _cream = Color(0xFFF4E7B0);
  static const _darkBrown = Color(0xFF2B1D12);
  static const _card = Color(0xFFFFFDF7);
  static const _lightRed = Color(0xFFE9A3A3);
  // Attendance level colors
  static const _attendanceGreen = Color(0xFF2E7D32);
  static const _attendanceAmber = Color(0xFFFF8F00);
  static const _attendanceRed = Color(0xFFC62828);

  @override
  Widget build(BuildContext context) {
    final type = '${visual['type'] ?? ''}'.trim();
    switch (type) {
      case 'attendance_chart':
        return _VisualFrame(child: _attendanceChart(context));
      case 'bar_chart':
        return _VisualFrame(child: _barChart(context, _genericItems()));
      case 'cards':
      case 'dashboard_widgets':
        return _VisualFrame(child: _cards(context));
      default:
        return const _VisualFrame(child: _EmptyVisual());
    }
  }

  Widget _attendanceChart(BuildContext context) {
    final subjects = _listOfMaps(visual['subjects']);
    if (subjects.isEmpty) return const _EmptyVisual();

    final items = subjects.map((subject) {
      final title = _firstText(subject, ['course_title', 'subject', 'title', 'name']);
      final code = _firstText(subject, ['course_code', 'code']);
      final label = title.isNotEmpty ? title : (code.isNotEmpty ? code : 'Subject');
      return _ChartItem(label: label, value: _numValue(subject['percentage']));
    }).toList();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _visualTitle('${visual['title'] ?? 'Subject-wise Attendance'}'),
        const SizedBox(height: 14),
        _barChart(context, items),
        const SizedBox(height: 8),
        // Color legend
        Wrap(
          spacing: 12,
          children: const [
            _LegendChip(color: _attendanceGreen, label: '≥85% Excellent'),
            _LegendChip(color: _attendanceAmber, label: '75–84% Safe'),
            _LegendChip(color: _attendanceRed, label: '<75% Low'),
          ],
        ),
        const SizedBox(height: 10),
        ...items.map((item) => Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Row(
                children: [
                  Container(width: 10, height: 10, decoration: BoxDecoration(color: _attendanceColor(item.value), shape: BoxShape.circle)),
                  const SizedBox(width: 8),
                  Expanded(child: Text(item.label, style: const TextStyle(color: _darkBrown, fontWeight: FontWeight.w700))),
                  Text('${_formatNumber(item.value)}%', style: TextStyle(color: _attendanceColor(item.value), fontWeight: FontWeight.w900)),
                ],
              ),
            )),
      ],
    );
  }

  Widget _barChart(BuildContext context, List<_ChartItem> items) {
    final safeItems = items.where((item) => item.label.trim().isNotEmpty).toList();
    if (safeItems.isEmpty) return const _EmptyVisual();
    final visibleItems = safeItems.take(8).toList();
    final maxY = visibleItems.map((item) => item.value).fold<double>(100, (max, value) => value > max ? value : max);

    return SizedBox(
      height: 220,
      child: BarChart(
        BarChartData(
          maxY: maxY,
          minY: 0,
          gridData: FlGridData(show: true, drawVerticalLine: false, getDrawingHorizontalLine: (_) => const FlLine(color: _cream, strokeWidth: 1)),
          borderData: FlBorderData(show: false),
          barTouchData: BarTouchData(enabled: true),
          titlesData: FlTitlesData(
            topTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
            rightTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
            leftTitles: AxisTitles(
              sideTitles: SideTitles(
                showTitles: true,
                reservedSize: 38,
                interval: 25,
                getTitlesWidget: (value, meta) => Text('${value.toInt()}%', style: const TextStyle(color: _darkBrown, fontSize: 10)),
              ),
            ),
            bottomTitles: AxisTitles(
              sideTitles: SideTitles(
                showTitles: true,
                reservedSize: 50,
                getTitlesWidget: (value, meta) {
                  final index = value.toInt();
                  if (index < 0 || index >= visibleItems.length) return const SizedBox.shrink();
                  final text = visibleItems[index].shortLabel;
                  return Padding(
                    padding: const EdgeInsets.only(top: 8),
                    child: Transform.rotate(
                      angle: -0.55,
                      child: Text(text, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: _darkBrown, fontSize: 10)),
                    ),
                  );
                },
              ),
            ),
          ),
          barGroups: [
            for (var i = 0; i < visibleItems.length; i++)
              BarChartGroupData(
                x: i,
                barRods: [
                  BarChartRodData(
                    toY: visibleItems[i].value,
                    color: _attendanceColor(visibleItems[i].value),
                    width: 18,
                    borderRadius: BorderRadius.circular(5),
                  ),
                ],
              ),
          ],
        ),
      ),
    );
  }

  Widget _cards(BuildContext context) {
    final cards = _listOfMaps(visual['cards']).isNotEmpty
        ? _listOfMaps(visual['cards'])
        : _listOfMaps(visual['widgets']);
    if (cards.isEmpty) return const _EmptyVisual();

    return Wrap(
      spacing: 12,
      runSpacing: 12,
      children: cards.map((card) {
        final title = _firstText(card, ['title', 'label', 'name']);
        final value = _firstText(card, ['value', 'status', 'amount', 'text']);
        return Container(
          width: 150,
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: _card,
            border: Border.all(color: _gold),
            borderRadius: BorderRadius.circular(15),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(title.isEmpty ? 'ERP' : title, style: const TextStyle(color: _maroon, fontWeight: FontWeight.w800, fontSize: 13)),
              const SizedBox(height: 8),
              Text(value.isEmpty ? 'No data available' : value, style: const TextStyle(color: _darkBrown, fontWeight: FontWeight.w700, fontSize: 16)),
            ],
          ),
        );
      }).toList(),
    );
  }

  List<_ChartItem> _genericItems() {
    final rows = _listOfMaps(visual['items']).isNotEmpty ? _listOfMaps(visual['items']) : _listOfMaps(visual['data']);
    return rows.map((row) {
      final label = _firstText(row, ['label', 'title', 'name', 'subject']);
      final value = _numValue(row['value'] ?? row['percentage'] ?? row['count']);
      return _ChartItem(label: label, value: value);
    }).toList();
  }

  Widget _visualTitle(String text) {
    return Text(
      text.trim().isEmpty ? 'ERP Summary' : text,
      style: const TextStyle(color: _maroon, fontSize: 16, fontWeight: FontWeight.w900),
    );
  }

  static Color _attendanceColor(double value) {
    if (value >= 85) return _attendanceGreen;   // Excellent
    if (value >= 75) return _attendanceAmber;   // Safe but watch out
    return _attendanceRed;                       // Below threshold — danger
  }

  static List<Map<String, dynamic>> _listOfMaps(dynamic value) {
    if (value is! List) return [];
    return value.whereType<Map>().map((item) => Map<String, dynamic>.from(item)).toList();
  }

  static String _firstText(Map<String, dynamic> map, List<String> keys) {
    for (final key in keys) {
      final value = '${map[key] ?? ''}'.trim();
      if (value.isNotEmpty && value.toLowerCase() != 'null') return value;
    }
    return '';
  }

  static double _numValue(dynamic value) {
    if (value is num) return value.toDouble();
    return double.tryParse('$value'.replaceAll('%', '').trim()) ?? 0;
  }

  static String _formatNumber(double value) {
    final rounded = value.toStringAsFixed(1);
    return rounded.endsWith('.0') ? rounded.substring(0, rounded.length - 2) : rounded;
  }
}

class _LegendChip extends StatelessWidget {
  const _LegendChip({required this.color, required this.label});
  final Color color;
  final String label;
  @override
  Widget build(BuildContext context) => Row(
    mainAxisSize: MainAxisSize.min,
    children: [
      Container(width: 10, height: 10, decoration: BoxDecoration(color: color, shape: BoxShape.circle)),
      const SizedBox(width: 4),
      Text(label, style: const TextStyle(fontSize: 11, color: VisualRenderer._darkBrown)),
    ],
  );
}

class _ChartItem {
  const _ChartItem({required this.label, required this.value});

  final String label;
  final double value;

  String get shortLabel {
    final trimmed = label.trim();
    if (trimmed.length <= 10) return trimmed;
    return '${trimmed.substring(0, 10)}...';
  }
}

class _VisualFrame extends StatelessWidget {
  const _VisualFrame({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: VisualRenderer._card,
        border: Border.all(color: VisualRenderer._gold),
        borderRadius: BorderRadius.circular(15),
      ),
      child: child,
    );
  }
}

class _EmptyVisual extends StatelessWidget {
  const _EmptyVisual();

  @override
  Widget build(BuildContext context) {
    return const Text(
      'No data available',
      style: TextStyle(color: VisualRenderer._darkBrown, fontWeight: FontWeight.w700),
    );
  }
}

class _Line extends StatelessWidget {
  const _Line({required this.label, required this.text});

  final String label;
  final String text;

  @override
  Widget build(BuildContext context) {
    return RichText(
      text: TextSpan(
        style: const TextStyle(color: Colors.black87, fontSize: 16, height: 1.45),
        children: [
          TextSpan(
            text: '$label: ',
            style: const TextStyle(color: Clr.maroon, fontWeight: FontWeight.w800),
          ),
          TextSpan(text: text),
        ],
      ),
    );
  }
}


class Field extends StatelessWidget {
  const Field({
    super.key,
    required this.label,
    required this.controller,
    this.obscure = false,
  });

  final String label;
  final TextEditingController controller;
  final bool obscure;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15),
        ),
        const SizedBox(height: 8),
        TextField(
          controller: controller,
          obscureText: obscure,
          decoration: const InputDecoration(
            filled: true,
            fillColor: Color(0xFFEAF1FF),
            border: OutlineInputBorder(),
            contentPadding: EdgeInsets.symmetric(horizontal: 14, vertical: 12),
          ),
        ),
      ],
    );
  }
}

class PageCard extends StatelessWidget {
  const PageCard({super.key, required this.title, required this.child});

  final String title;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(24),
      child: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 1100),
          child: Container(
            width: double.infinity,
            padding: const EdgeInsets.all(22),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: Clr.maroon.withValues(alpha: 0.18)),
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withValues(alpha: 0.08),
                  blurRadius: 18,
                  offset: const Offset(0, 8),
                ),
              ],
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: const TextStyle(
                    color: Clr.maroon,
                    fontSize: 28,
                    fontWeight: FontWeight.w900,
                  ),
                ),
                const SizedBox(height: 20),
                child,
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class InfoGrid extends StatelessWidget {
  const InfoGrid({super.key, required this.rows});

  final List<(String, String)> rows;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final itemWidth = constraints.maxWidth < 680
            ? constraints.maxWidth
            : (constraints.maxWidth - 14) / 2;
        return Wrap(
          spacing: 14,
          runSpacing: 14,
          children: rows
              .map(
                (row) => SizedBox(
                  width: itemWidth,
                  child: Container(
                    padding: const EdgeInsets.all(14),
                    decoration: BoxDecoration(
                      color: const Color(0xFFFFF8DC),
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(color: Clr.gold.withValues(alpha: 0.55)),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          row.$1,
                          style: const TextStyle(
                            color: Clr.maroon,
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                        const SizedBox(height: 6),
                        Text(row.$2, style: const TextStyle(fontSize: 16)),
                      ],
                    ),
                  ),
                ),
              )
              .toList(),
        );
      },
    );
  }
}

class Status extends StatelessWidget {
  const Status({super.key, required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: const Color(0xFFFFF2D6),
        borderRadius: BorderRadius.circular(8),
        border: const Border(left: BorderSide(color: Clr.gold, width: 5)),
      ),
      child: Text(
        text,
        style: const TextStyle(fontWeight: FontWeight.w800, color: Clr.maroon),
      ),
    );
  }
}

class DataBlock extends StatelessWidget {
  const DataBlock({
    super.key,
    required this.title,
    required this.cols,
    required this.rows,
  });

  final String title;
  final List<String> cols;
  final List<List<String>> rows;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          title,
          style: const TextStyle(
            color: Clr.maroon,
            fontSize: 20,
            fontWeight: FontWeight.w900,
          ),
        ),
        const SizedBox(height: 10),
        SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: DataTable(
            headingRowColor: WidgetStateProperty.all(const Color(0xFFFFF4BD)),
            border: TableBorder.all(color: Colors.black12),
            columns: cols
                .map(
                  (col) => DataColumn(
                    label: Text(
                      col,
                      style: const TextStyle(fontWeight: FontWeight.w900),
                    ),
                  ),
                )
                .toList(),
            rows: rows
                .map(
                  (row) => DataRow(
                    cells: cols.asMap().entries.map((entry) {
                      final value = entry.key < row.length ? row[entry.key] : '';
                      return DataCell(Text(value));
                    }).toList(),
                  ),
                )
                .toList(),
          ),
        ),
      ],
    );
  }
}

class ActionCard extends StatelessWidget {
  const ActionCard({
    super.key,
    required this.title,
    required this.text,
    required this.icon,
  });

  final String title;
  final String text;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: const Color(0xFFFFF4BD),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: Clr.maroon, width: 2),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, color: Clr.maroon, size: 30),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: const TextStyle(
                    color: Clr.maroon,
                    fontWeight: FontWeight.w900,
                    fontSize: 17,
                  ),
                ),
                const SizedBox(height: 6),
                Text(text),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class Metric extends StatelessWidget {
  const Metric({super.key, required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 170,
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Clr.maroon,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: const TextStyle(color: Colors.white70, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 8),
          Text(
            value,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 22,
              fontWeight: FontWeight.w900,
            ),
          ),
        ],
      ),
    );
  }
}
