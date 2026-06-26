import 'dart:async';
import 'dart:convert';
import 'dart:math' as math;
import 'dart:ui';
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
    } catch (e) {
      demo = true;
      debugPrint('LOGIN ERROR: $e');
      setState(() { loading = false; error = 'Connection failed: $e'; });
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
  Map<String, String> resultFilter = {};
  late String reply = 'Hello ${widget.student.name}! How can I help you today?';
  Offset? _orbPos; // null = default bottom-right; set on first drag

  void open(int i, [String? msg]) => setState(() {
    page = i;
    if (msg != null) reply = msg;
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
          // ── Draggable voice orb — snaps to left/right edge ─────────
          Builder(builder: (ctx) {
            final mq = MediaQuery.of(ctx);
            final screen = mq.size;
            final safePad = mq.padding;
            const orbW = 82.0;   // 72 orb + 10 outer ring
            const orbH = 82.0 + 24 + 5 + 18; // orb + close btn + gap + label
            const margin = 24.0;
            // Default: bottom-right, 24px from safe edges
            final defaultPos = Offset(
              screen.width - orbW - margin,
              screen.height - safePad.bottom - orbH - margin,
            );
            final pos = _orbPos ?? defaultPos;

            return Positioned(
              left: pos.dx,
              top: pos.dy,
              child: SafeArea(
                child: GestureDetector(
                  onPanUpdate: (d) => setState(() {
                    final cur = _orbPos ?? defaultPos;
                    _orbPos = Offset(
                      (cur.dx + d.delta.dx).clamp(margin, screen.width - orbW - margin),
                      (cur.dy + d.delta.dy).clamp(safePad.top + margin, screen.height - safePad.bottom - orbH - margin),
                    );
                  }),
                  // Snap to nearest edge on finger lift
                  onPanEnd: (_) => setState(() {
                    final cur = _orbPos ?? defaultPos;
                    final snapRight = cur.dx + orbW / 2 > screen.width / 2;
                    _orbPos = Offset(
                      snapRight ? screen.width - orbW - margin : margin,
                      cur.dy.clamp(safePad.top + margin, screen.height - safePad.bottom - orbH - margin),
                    );
                  }),
                  child: bot
                      ? VapiVoiceBox(
                          lang: lang,
                          user: '',
                          reply: reply,
                          onClose: () => setState(() => bot = false),
                          onLang: (v) => setState(() => lang = v),
                          onAsk: (_) {},
                          onNavigate: _applyVoiceNavigation,
                        )
                      : _GlowMicButton(
                          size: 60,
                          state: _MicState.idle,
                          onTap: () => setState(() => bot = true),
                        ),
                ),
              ),
            );
          }),
        ],
      ),
    );
  }

  String _applyVoiceNavigation(String path, String pageName, [Map<String, dynamic>? resultRequest]) {
    final target = _pageIndexForPath(path, pageName);
    if (target == null) return 'I could not open that page.';
    setState(() {
      page = target;
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
    return '$pageName page opened.';
  }

  int? _pageIndexForPath(String path, String pageName) {
    final pageKey = pageName.toLowerCase().trim();
    final pathKey = path.split('?').first.toLowerCase().trim();
    const byPage = {
      'home': 0, 'profile': 1, 'registration': 2, 're_registration': 2,
      'resit': 2, 'apply_exam': 2, 'payment': 3, 'results': 4, 'result': 4,
      'certificate': 5, 'dashboard': 6, 'attendance': 7,
      'hallticket': 8, 'hall_ticket': 8, 'hall ticket': 8,
    };
    const byPath = {
      '/home': 0, '/profile': 1, '/registration': 2, '/re-registration': 2,
      '/resit': 2, '/apply-exam': 2, '/payment': 3, '/results': 4,
      '/certificate': 5, '/dashboard': 6, '/attendance': 7,
      '/attendance-analytics': 7, '/hall-ticket': 8,
    };
    return byPath[pathKey] ?? byPage[pageKey];
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

class _VapiVoiceBoxState extends State<VapiVoiceBox> with TickerProviderStateMixin {
  final _textCtrl = TextEditingController();
  late final AnimationController _pulse;
  late final Animation<double> _pulseAnim;
  late final AnimationController _shake;
  late final AnimationController _spin;
  bool _connecting = false;
  bool _callActive = false;
  bool _speaking = false;
  // ignore: unused_field
  String _status = '';
  // ignore: unused_field
  String _source = 'vapi';
  String _localLang = 'English';
  // ignore: unused_field
  String _localUser = '';
  // ignore: unused_field
  String _localReply = '';
  // ignore: unused_field
  bool _hasInteracted = false;
  // ignore: unused_field
  String? _error;
  // ignore: unused_field
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
    _pulse = AnimationController(vsync: this, duration: const Duration(milliseconds: 1600))..repeat(reverse: true);
    _pulseAnim = Tween<double>(begin: 1.0, end: 1.0).animate(_pulse); // unused scale — kept for compat
    _shake = AnimationController(vsync: this, duration: const Duration(milliseconds: 120))..repeat(reverse: true);
    _spin  = AnimationController(vsync: this, duration: const Duration(seconds: 3))..repeat();
    _localLang = widget.lang;
    _localUser = widget.user;
    _localReply = widget.reply;
    // Auto-start Vapi as soon as the panel opens
    WidgetsBinding.instance.addPostFrameCallback((_) => _startVapi());
  }

  @override
  void didUpdateWidget(covariant VapiVoiceBox oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.lang != widget.lang) _localLang = widget.lang;
    if (oldWidget.user != widget.user) {
      _localUser = widget.user;
      _localReply = '';
    }
    if (oldWidget.reply != widget.reply) _localReply = widget.reply;
  }

  @override
  void dispose() {
    _pulse.dispose();
    _shake.dispose();
    _spin.dispose();
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

    // Enable hardware AEC before WebRTC initialises — MODE_IN_COMMUNICATION activates
    // the device echo canceller so the bot's speaker output is not fed back into the mic.
    await _setSpeakerphone();

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
          _hasInteracted = true;
        } else {
          _localUser = transcript;
          _hasInteracted = true;
          _localReply = '';
          // Do NOT clear _visual here — keep the previous chart visible
          // until the new answer arrives with its own visual (or none).
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
      _hasInteracted = true;
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
    // Only use truly unique server-side IDs for deduplication.
    // Never use type/path/page alone — that would block navigating to the same page twice.
    final uniqueIds = [
      result['tool_execution_id'],
      result['request_id'],
      result['call_id'],
      debug['tool_execution_id'],
      debug['request_id'],
      debug['call_id'],
    ].map((v) => '$v'.trim()).where((v) => v.isNotEmpty && v != 'null').toList();

    if (uniqueIds.isEmpty) return '';  // no unique ID → never deduplicate
    return '${uniqueIds.first}|${action['type']}|${action['path']}';
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


  Future<void> _close() async {
    _stopVapi();
    widget.onClose();
  }

  @override
  Widget build(BuildContext context) {
    final isSpeaking   = _speaking;
    final isListening  = _callActive && !_speaking;
    final isConnecting = _connecting;
    final screenH      = MediaQuery.sizeOf(context).height;

    const double orbSize = 72.0;
    const Color  gold    = Color(0xFFD4A843);

    // ── Status label ─────────────────────────────────────────────
    final String statusLabel = isConnecting
        ? 'Connecting...'
        : isSpeaking
            ? 'Speaking...'
            : isListening
                ? 'Listening...'
                : '';

    // ── GMU logo — cropped to emblem only (top 68% of image hides text) ──
    final Widget gmuLogo = ClipRect(
      child: Align(
        alignment: const Alignment(0.0, -0.6),
        heightFactor: 0.68,
        child: Image.asset(
          'assets/gmu_logo.png',
          width: 56,
          fit: BoxFit.fitWidth,
        ),
      ),
    );

    // ── Inner content: logo + state animation stacked ────────────
    Widget innerContent;
    if (isConnecting) {
      innerContent = GestureDetector(
        onTap: _startVapi,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            gmuLogo,
            const SizedBox(height: 6),
            const _SequentialDots(color: gold),
          ],
        ),
      );
    } else if (isSpeaking) {
      innerContent = GestureDetector(
        onTap: _stopVapi,
        child: AnimatedBuilder(
          animation: _shake,
          builder: (_, child) => Transform.translate(
            offset: Offset((_shake.value - 0.5) * 1.4, 0),
            child: child,
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              gmuLogo,
              const SizedBox(height: 5),
              const _VoiceWaveform(color: gold, barCount: 5),
            ],
          ),
        ),
      );
    } else if (isListening) {
      innerContent = GestureDetector(
        onTap: _stopVapi,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            gmuLogo,
            const SizedBox(height: 5),
            const _VoiceWaveform(color: Colors.white70, barCount: 5),
          ],
        ),
      );
    } else {
      // Idle
      innerContent = GestureDetector(
        onTap: _startVapi,
        child: gmuLogo,
      );
    }

    // ── Outer ring decorations ────────────────────────────────────
    Widget orbDecorated = SizedBox(
      // fix: constrain to orbSize so nothing spills into screen edge
      width: orbSize + 10,
      height: orbSize + 10,
      child: Stack(
        alignment: Alignment.center,
        clipBehavior: Clip.none,
        children: [
          // Rotating gold sweep — connecting
          if (isConnecting)
            RotationTransition(
              turns: _spin,
              child: Container(
                width: orbSize + 8,
                height: orbSize + 8,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  gradient: SweepGradient(
                    colors: [
                      gold.withValues(alpha: 0.0),
                      gold.withValues(alpha: 0.9),
                      gold.withValues(alpha: 0.0),
                    ],
                    stops: const [0.0, 0.5, 1.0],
                  ),
                ),
              ),
            ),
          // Breathing ring — listening
          if (isListening)
            AnimatedBuilder(
              animation: _pulse,
              builder: (_, __) {
                final t = _pulse.value;
                return Container(
                  width: orbSize + 6,
                  height: orbSize + 6,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    border: Border.all(
                      color: gold.withValues(alpha: 0.20 + t * 0.55),
                      width: 1.0 + t * 1.2,
                    ),
                  ),
                );
              },
            ),
          // Pulse ring — speaking
          if (isSpeaking)
            AnimatedBuilder(
              animation: _pulse,
              builder: (_, __) {
                final t = _pulse.value;
                return Container(
                  width: orbSize + 6,
                  height: orbSize + 6,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    border: Border.all(
                      color: gold.withValues(alpha: 0.25 + t * 0.50),
                      width: 1.0 + t * 1.0,
                    ),
                  ),
                );
              },
            ),
          // Core 72×72 matte circle — always
          Container(
            width: orbSize,
            height: orbSize,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              gradient: const LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [Color(0xFFA52020), Color(0xFF6E0E0E)],
              ),
              border: Border.all(
                color: gold.withValues(alpha: 0.55),
                width: 1.2,
              ),
            ),
            child: ClipOval(
              child: Center(child: innerContent),
            ),
          ),
        ],
      ),
    );

    const double cardW = 280.0;

    return Material(
      color: Colors.transparent,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          // ── Visual card ──────────────────────────────────────────
          if (_visual != null)
            Container(
              width: cardW,
              margin: const EdgeInsets.only(bottom: 8),
              constraints: BoxConstraints(maxHeight: screenH * 0.25),
              decoration: BoxDecoration(
                color: Colors.white.withValues(alpha: 0.95),
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: Clr.maroon.withValues(alpha: 0.12)),
                boxShadow: [BoxShadow(color: Colors.black.withValues(alpha: 0.08), blurRadius: 10, offset: const Offset(0, 2))],
              ),
              child: ClipRRect(
                borderRadius: BorderRadius.circular(14),
                child: SingleChildScrollView(
                  padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
                  child: VisualRenderer(visual: _visual!),
                ),
              ),
            ),

          // ── Close button ─────────────────────────────────────────
          Align(
            alignment: Alignment.centerRight,
            child: GestureDetector(
              onTap: _close,
              child: Container(
                width: 24,
                height: 24,
                decoration: BoxDecoration(
                  color: Clr.maroon.withValues(alpha: 0.90),
                  shape: BoxShape.circle,
                  border: Border.all(color: gold.withValues(alpha: 0.45), width: 1),
                ),
                child: const Icon(Icons.close_rounded, color: Colors.white, size: 13),
              ),
            ),
          ),

          const SizedBox(height: 5),

          // ── Orb ─────────────────────────────────────────────────
          orbDecorated,

          // ── Status label ─────────────────────────────────────────
          if (statusLabel.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(
                statusLabel,
                style: TextStyle(
                  color: Colors.white.withValues(alpha: 0.85),
                  fontSize: 10,
                  fontWeight: FontWeight.w600,
                  letterSpacing: 0.4,
                  shadows: const [Shadow(color: Colors.black26, blurRadius: 4)],
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _SoundWave extends StatefulWidget {
  const _SoundWave({required this.active, this.barColor});
  final bool active;
  final Color? barColor;
  @override
  State<_SoundWave> createState() => _SoundWaveState();
}

class _SoundWaveState extends State<_SoundWave> with TickerProviderStateMixin {
  final List<AnimationController> _ctrls = [];
  final List<Animation<double>> _anims = [];
  static const _heights = [10.0, 20.0, 28.0, 18.0, 12.0];
  static const _delays  = [0, 100, 180, 80, 220];

  @override
  void initState() {
    super.initState();
    for (var i = 0; i < _heights.length; i++) {
      final ctrl = AnimationController(
        vsync: this,
        duration: Duration(milliseconds: 500 + i * 60),
      )..repeat(reverse: true);
      _ctrls.add(ctrl);
      _anims.add(Tween<double>(begin: 4, end: _heights[i]).animate(
        CurvedAnimation(parent: ctrl, curve: Curves.easeInOut),
      ));
      Future.delayed(Duration(milliseconds: _delays[i]), () {
        if (mounted) ctrl.forward();
      });
    }
  }

  @override
  void dispose() {
    for (final c in _ctrls) { c.dispose(); }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Row(
    mainAxisSize: MainAxisSize.min,
    crossAxisAlignment: CrossAxisAlignment.center,
    children: List.generate(_heights.length, (i) => Padding(
      padding: const EdgeInsets.symmetric(horizontal: 3),
      child: AnimatedBuilder(
        animation: _anims[i],
        builder: (context2, snap) => Container(
          width: 5,
          height: widget.active ? _anims[i].value : 6,
          decoration: BoxDecoration(
            color: (widget.barColor ?? Clr.maroon).withValues(alpha: widget.active ? 0.85 : 0.3),
            borderRadius: BorderRadius.circular(3),
          ),
        ),
      ),
    )),
  );
}

// ── Sequential left-to-right dots (Connecting / Thinking) ────────────────────
class _SequentialDots extends StatefulWidget {
  const _SequentialDots({required this.color});
  final Color color;
  @override
  State<_SequentialDots> createState() => _SequentialDotsState();
}

class _SequentialDotsState extends State<_SequentialDots> with SingleTickerProviderStateMixin {
  late final AnimationController _ctrl;

  @override
  void initState() {
    super.initState();
    _ctrl = AnimationController(vsync: this, duration: const Duration(milliseconds: 900))..repeat();
  }

  @override
  void dispose() { _ctrl.dispose(); super.dispose(); }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _ctrl,
      builder: (_, __) {
        // t cycles 0→1; each dot is "active" during its 1/3 window
        final t = _ctrl.value;
        return Row(
          mainAxisSize: MainAxisSize.min,
          children: List.generate(3, (i) {
            final phase  = i / 3.0;
            final active = ((t - phase + 1.0) % 1.0) < 0.35;
            return Padding(
              padding: const EdgeInsets.symmetric(horizontal: 3.5),
              child: AnimatedContainer(
                duration: const Duration(milliseconds: 80),
                width: 6,
                height: 6,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: widget.color.withValues(alpha: active ? 1.0 : 0.28),
                ),
              ),
            );
          }),
        );
      },
    );
  }
}

// ── Animated voice waveform bars (Listening / Speaking) ──────────────────────
class _VoiceWaveform extends StatefulWidget {
  const _VoiceWaveform({required this.color, this.barCount = 5});
  final Color color;
  final int   barCount;
  @override
  State<_VoiceWaveform> createState() => _VoiceWaveformState();
}

class _VoiceWaveformState extends State<_VoiceWaveform> with TickerProviderStateMixin {
  final List<AnimationController> _ctrls = [];
  final List<Animation<double>>   _anims = [];

  // Natural-feeling heights and speeds per bar
  static const _maxH   = [10.0, 20.0, 26.0, 18.0, 12.0, 22.0, 8.0];
  static const _speeds = [480,  540,   420,  600,  500,  460,  580];
  static const _delays = [0,    90,   160,   60,  220,  130,  300];

  @override
  void initState() {
    super.initState();
    for (var i = 0; i < widget.barCount; i++) {
      final ctrl = AnimationController(
        vsync: this,
        duration: Duration(milliseconds: _speeds[i % _speeds.length]),
      )..repeat(reverse: true);
      _ctrls.add(ctrl);
      _anims.add(Tween<double>(begin: 3.0, end: _maxH[i % _maxH.length]).animate(
        CurvedAnimation(parent: ctrl, curve: Curves.easeInOut),
      ));
      Future.delayed(Duration(milliseconds: _delays[i % _delays.length]), () {
        if (mounted) ctrl.forward();
      });
    }
  }

  @override
  void dispose() { for (final c in _ctrls) c.dispose(); super.dispose(); }

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: (widget.barCount * 8.0).clamp(32.0, 52.0),
      height: 28,
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        crossAxisAlignment: CrossAxisAlignment.center,
        children: List.generate(widget.barCount, (i) => Padding(
          padding: const EdgeInsets.symmetric(horizontal: 2.0),
          child: AnimatedBuilder(
            animation: _anims[i],
            builder: (_, __) => Container(
              width: 4,
              height: _anims[i].value,
              decoration: BoxDecoration(
                color: widget.color.withValues(alpha: 0.88),
                borderRadius: BorderRadius.circular(2),
              ),
            ),
          ),
        )),
      ),
    );
  }
}

enum _MicState { idle, connecting, listening, speaking }

/// Bixby-style glowing voice orb: a radial-gradient core wrapped in a slowly
/// rotating conic "shine" ring, with concentric pulse rings when active.
/// Colors shift per state so the user can read connecting / listening / speaking
/// at a glance without needing a text label.
class _GlowMicButton extends StatefulWidget {
  const _GlowMicButton({required this.size, required this.state, required this.onTap});
  final double size;
  final _MicState state;
  final VoidCallback? onTap;

  @override
  State<_GlowMicButton> createState() => _GlowMicButtonState();
}

class _GlowMicButtonState extends State<_GlowMicButton> with TickerProviderStateMixin {
  late final AnimationController _spin;
  late final AnimationController _pulse;

  @override
  void initState() {
    super.initState();
    _spin = AnimationController(vsync: this, duration: const Duration(seconds: 4))..repeat();
    _pulse = AnimationController(vsync: this, duration: const Duration(milliseconds: 1300))..repeat();
  }

  @override
  void dispose() {
    _spin.dispose();
    _pulse.dispose();
    super.dispose();
  }

  // Single maroon theme for every state — only the icon and the wave motion change.
  List<Color> get _coreColors => const [Color(0xFFB23A3A), Color(0xFF9B1C1C), Color(0xFF6B0F0F)];

  Color get _glow => Clr.maroon;

  IconData get _icon {
    switch (widget.state) {
      case _MicState.connecting:
        return Icons.more_horiz_rounded;
      case _MicState.speaking:
        return Icons.graphic_eq_rounded;
      case _MicState.listening:
        return Icons.stop_rounded;
      case _MicState.idle:
        return Icons.mic_rounded;
    }
  }

  bool get _active => widget.state != _MicState.idle;

  @override
  Widget build(BuildContext context) {
    final ringBox = widget.size * 1.7;
    return GestureDetector(
      onTap: widget.onTap,
      behavior: HitTestBehavior.opaque,
      child: SizedBox(
        width: ringBox,
        height: ringBox,
        child: Stack(
          alignment: Alignment.center,
          children: [
            // Concentric expanding pulse rings (only while active).
            if (_active)
              AnimatedBuilder(
                animation: _pulse,
                builder: (context, _) {
                  return Stack(
                    alignment: Alignment.center,
                    children: List.generate(2, (i) {
                      final t = ((_pulse.value + i * 0.5) % 1.0);
                      return Container(
                        width: widget.size + (ringBox - widget.size) * t,
                        height: widget.size + (ringBox - widget.size) * t,
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          border: Border.all(color: Colors.white.withValues(alpha: (1 - t) * 0.55), width: 2),
                        ),
                      );
                    }),
                  );
                },
              ),
            // Rotating conic "shine" ring for the Bixby sheen.
            RotationTransition(
              turns: _spin,
              child: Container(
                width: widget.size + 8,
                height: widget.size + 8,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  gradient: SweepGradient(
                    colors: [
                      _glow.withValues(alpha: 0.0),
                      _glow.withValues(alpha: 0.7),
                      Colors.white.withValues(alpha: 0.9),
                      _glow.withValues(alpha: 0.7),
                      _glow.withValues(alpha: 0.0),
                    ],
                    stops: const [0.0, 0.25, 0.5, 0.75, 1.0],
                  ),
                ),
              ),
            ),
            // Core orb.
            Container(
              width: widget.size,
              height: widget.size,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                gradient: RadialGradient(
                  colors: _coreColors,
                  center: const Alignment(-0.3, -0.4),
                  radius: 1.1,
                ),
                boxShadow: [
                  BoxShadow(color: _glow.withValues(alpha: 0.55), blurRadius: _active ? 22 : 12, spreadRadius: _active ? 3 : 0),
                ],
              ),
              child: Icon(_icon, color: Colors.white, size: widget.size * 0.42),
            ),
          ],
        ),
      ),
    );
  }
}

class _PulsingDot extends StatefulWidget {
  const _PulsingDot({required this.color, required this.delay});
  final Color color;
  final int delay;
  @override
  State<_PulsingDot> createState() => _PulsingDotState();
}

class _PulsingDotState extends State<_PulsingDot> with SingleTickerProviderStateMixin {
  late AnimationController _ctrl;
  late Animation<double> _anim;

  @override
  void initState() {
    super.initState();
    _ctrl = AnimationController(vsync: this, duration: const Duration(milliseconds: 600))..repeat(reverse: true);
    _anim = Tween<double>(begin: 0.4, end: 1.0).animate(CurvedAnimation(parent: _ctrl, curve: Curves.easeInOut));
    Future.delayed(Duration(milliseconds: widget.delay), () {
      if (mounted) _ctrl.forward();
    });
  }

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => FadeTransition(
    opacity: _anim,
    child: Container(
      width: 8,
      height: 8,
      decoration: BoxDecoration(color: widget.color, shape: BoxShape.circle),
    ),
  );
}

class VisualRenderer extends StatelessWidget {
  const VisualRenderer({super.key, required this.visual});

  final Map<String, dynamic> visual;

  static const _maroon = Color(0xFF6F1D1B);
  static const _gold = Color(0xFFD8B85A);
  static const _cream = Color(0xFFF4E7B0);
  static const _darkBrown = Color(0xFF2B1D12);
  static const _card = Color(0xFFFFFDF7);
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
      case 'info_card':
        return _VisualFrame(child: _infoCard(context));
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

  Widget _infoCard(BuildContext context) {
    final title = '${visual['title'] ?? ''}';
    final cards = _listOfMaps(visual['cards']);
    if (cards.isEmpty) return const _EmptyVisual();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (title.isNotEmpty) ...[
          Text(title, style: const TextStyle(color: _maroon, fontSize: 12, fontWeight: FontWeight.w900, letterSpacing: 0.5)),
          const SizedBox(height: 6),
          Container(height: 1, color: _gold.withValues(alpha: 0.5)),
          const SizedBox(height: 4),
        ],
        ...List.generate(cards.length, (i) {
          final card = cards[i];
          final label = _firstText(card, ['title', 'label']);
          final value = _firstText(card, ['value', 'text']);
          return Column(
            children: [
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 6),
                child: Row(
                  children: [
                    Expanded(
                      flex: 2,
                      child: Text(label, style: TextStyle(color: _maroon.withValues(alpha: 0.8), fontWeight: FontWeight.w600, fontSize: 12)),
                    ),
                    Expanded(
                      flex: 3,
                      child: Text(
                        value,
                        style: const TextStyle(color: _darkBrown, fontWeight: FontWeight.w800, fontSize: 13),
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                  ],
                ),
              ),
              if (i < cards.length - 1)
                Container(height: 0.5, color: _gold.withValues(alpha: 0.35)),
            ],
          );
        }),
      ],
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
