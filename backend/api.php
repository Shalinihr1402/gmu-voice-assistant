<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Sample student data
$studentData = [
    'username' => '822991167838',
    'name' => 'I M SHIVAKUMARA',
    'usn' => 'P24C01CA026',
    'mobile' => '8105020220',
    'year' => 2,
    'sem' => 3,
    'branch' => 'MCA',
    'academicYear' => '2025-26',
    'season' => 'ODD',
    'faculty' => 'FCIT',
    'school' => 'SCA',
    'quota' => 'MGMT',
    'designation' => 'Student',
    'section' => 'NA',
    'discipline' => 'MCA'
];

$method = $_SERVER['REQUEST_METHOD'];
$path = $_SERVER['REQUEST_URI'];
$pathParts = explode('/', trim(parse_url($path, PHP_URL_PATH), '/'));

// Remove 'api' from path if present
if ($pathParts[0] === 'api') {
    array_shift($pathParts);
}

$endpoint = $pathParts[0] ?? '';

switch ($endpoint) {
    case 'student':
    case '':
        if ($method === 'GET') {
            echo json_encode([
                'success' => true,
                'data' => $studentData
            ]);
        } elseif ($method === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (isset($input['mobile'])) {
                $studentData['mobile'] = $input['mobile'];
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => $studentData
            ]);
        }
        break;
    
    case 'courses':
        if ($method === 'GET') {
            $courses = [
                [
                    'code' => 'MCA301',
                    'name' => 'Software Engineering and Project Management',
                    'group' => 'ACADEMIC',
                    'type' => 'CORE'
                ],
                [
                    'code' => 'MCA302',
                    'name' => 'Digital Image Processing',
                    'group' => 'ACADEMIC',
                    'type' => 'CORE'
                ],
                [
                    'code' => 'MCA303',
                    'name' => 'Web Technology',
                    'group' => 'ACADEMIC',
                    'type' => 'CORE'
                ],
                [
                    'code' => 'MCA304',
                    'name' => 'Cloud Computing',
                    'group' => 'ACADEMIC',
                    'type' => 'ELECTIVE'
                ]
            ];
            
            echo json_encode([
                'success' => true,
                'data' => $courses
            ]);
        }
        break;
    
    case 'fees':
        if ($method === 'GET') {
            $fees = [
                'programFee' => [
                    'total' => 113631.00,
                    'toPay' => 113631.00,
                    'paid' => 0.00,
                    'balance' => 113631.00
                ],
                'skillAssessment' => [
                    'total' => 19369.00,
                    'toPay' => 19369.00,
                    'paid' => 0.00,
                    'balance' => 19369.00
                ],
                'lateFee' => [
                    'delayedWeeks' => 7,
                    'finePayable' => 3500,
                    'paid' => 0,
                    'balance' => 3500
                ]
            ];
            
            echo json_encode([
                'success' => true,
                'data' => $fees
            ]);
        }
        break;
    
    default:
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Endpoint not found'
        ]);
        break;
}
?>
