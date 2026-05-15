<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MembershipApplicationImportSampleController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        $filename = 'membership-applications-sample-20.csv';

        $headers = [
            'name',
            'email',
            'password',
            'application_type',
            'gender',
            'marital_status',
            'national_id',
            'date_of_birth',
            'membership_date',
            'address',
            'city',
            'home_phone',
            'work_phone',
            'mobile_phone',
            'work_place',
            'residency_place',
            'occupation',
            'employer',
            'monthly_income',
            'bank_account_number',
            'iban',
            'next_of_kin_name',
            'next_of_kin_phone',
        ];

        $rows = [
            ['Ahmed Al Saud', 'sample.applicant01@example.test', 'TempPass@01', 'new', 'male', 'married', '1000000001', '1989-01-14', '2026-01-10', 'King Fahd Rd, Building 12', 'Riyadh', '0114000101', '0114001101', '0501000101', 'Aramco HQ', 'Riyadh', 'Engineer', 'Aramco', '18500', '101000000001', 'SA030000000000101000000001', 'Mona Al Saud', '0503000101'],
            ['Fatimah Hassan', 'sample.applicant02@example.test', '', 'renew', 'female', 'married', '1000000002', '1991-03-22', '2026-02-01', 'Olaya St, Apt 8', 'Riyadh', '', '', '0501000102', 'King Faisal Hospital', 'Riyadh', 'Doctor', 'KFSH', '24000', '101000000002', 'SA030000000000101000000002', 'Hassan Ali', '0503000102'],
            ['Yousef Nasser', 'sample.applicant03@example.test', '', 'resume', 'male', 'single', '1000000003', '1995-06-17', '', 'Prince Sultan St', 'Jeddah', '', '0124500103', '0501000103', '', 'Jeddah', '', '', '', '101000000003', 'SA030000000000101000000003', 'Nasser Yousef', '0503000103'],
            ['Aisha Omar', 'sample.applicant04@example.test', 'StrongPass#04', 'new', 'female', 'single', '1000000004', '1998-02-10', '', 'Al Malaz District', 'Riyadh', '', '', '0501000104', '', 'Riyadh', 'Teacher', 'Public School', '9500', '101000000004', 'SA030000000000101000000004', 'Omar Saeed', '0503000104'],
            ['Khalid Fahad', 'sample.applicant05@example.test', '', 'new', 'male', 'married', '1000000005', '1987-11-30', '2025-12-15', 'Corniche Rd', 'Dammam', '0135000105', '', '0501000105', 'SABIC Plant', 'Dammam', 'Supervisor', 'SABIC', '16500.50', '101000000005', 'SA030000000000101000000005', 'Fahad Khalid', '0503000105'],
            ['Nora Abdullah', 'sample.applicant06@example.test', '', 'renew', 'female', 'widowed', '1000000006', '1982-07-19', '2026-03-01', 'Al Aziziyah', 'Makkah', '', '', '0501000106', '', 'Makkah', '', '', '', '101000000006', 'SA030000000000101000000006', 'Abdullah Nora', '0503000106'],
            ['Salem Majed', 'sample.applicant07@example.test', 'TempPass@07', 'resume', 'male', 'divorced', '1000000007', '1990-09-12', '', 'Al Rawdah', 'Jeddah', '', '0124500107', '0501000107', 'Port Authority', 'Jeddah', 'Analyst', 'Jeddah Port', '12200', '101000000007', 'SA030000000000101000000007', 'Majed Salem', '0503000107'],
            ['Rana Ibrahim', 'sample.applicant08@example.test', '', 'new', 'female', 'married', '1000000008', '1993-12-08', '', 'King Abdulaziz Rd', 'Khobar', '', '', '0501000108', 'Private Clinic', 'Khobar', 'Nurse', 'Al Amal', '9800', '101000000008', 'SA030000000000101000000008', 'Ibrahim Rana', '0503000108'],
            ['Saad Tariq', 'sample.applicant09@example.test', '', 'new', 'male', 'single', '1000000009', '1999-04-26', '', 'Batha St', 'Riyadh', '', '', '0501000109', '', 'Riyadh', 'Student', 'KSU', '3200', '101000000009', 'SA030000000000101000000009', 'Tariq Saad', '0503000109'],
            ['Lina Yasin', 'sample.applicant10@example.test', 'TempPass@10', 'renew', 'female', 'married', '1000000010', '1986-05-11', '2026-01-20', 'Northern Ring Rd', 'Riyadh', '0114000110', '', '0501000110', 'MoE', 'Riyadh', 'Administrator', 'Ministry of Education', '14100', '101000000010', 'SA030000000000101000000010', 'Yasin Lina', '0503000110'],
            ['Feras Adel', 'sample.applicant11@example.test', '', 'resume', 'male', 'single', '1000000011', '1994-10-03', '', 'King Abdullah District', 'Riyadh', '', '', '0501000111', 'STC Tower', 'Riyadh', 'Developer', 'STC', '17000', '101000000011', 'SA030000000000101000000011', 'Adel Feras', '0503000111'],
            ['Huda Bilal', 'sample.applicant12@example.test', '', 'new', 'female', 'married', '1000000012', '1992-08-18', '', 'Al Safa', 'Jeddah', '', '', '0501000112', '', 'Jeddah', 'Accountant', 'Private Co', '11600', '101000000012', 'SA030000000000101000000012', 'Bilal Huda', '0503000112'],
            ['Mazen Sami', 'sample.applicant13@example.test', 'SecurePass#13', 'renew', 'male', 'married', '1000000013', '1984-01-25', '2026-02-15', 'Al Nakheel', 'Riyadh', '0114000113', '', '0501000113', 'National Guard', 'Riyadh', 'Officer', 'NG', '21000', '101000000013', 'SA030000000000101000000013', 'Sami Mazen', '0503000113'],
            ['Reem Fawzi', 'sample.applicant14@example.test', '', 'new', 'female', 'single', '1000000014', '2000-06-09', '', 'Al Hamra', 'Dammam', '', '', '0501000114', '', 'Dammam', 'Designer', 'Freelance', '7300', '101000000014', 'SA030000000000101000000014', 'Fawzi Reem', '0503000114'],
            ['Bader Saif', 'sample.applicant15@example.test', '', 'resume', 'male', 'other', '1000000015', '1991-02-27', '', 'Umm Al Hamam', 'Riyadh', '', '0114002115', '0501000115', 'Alinma Bank', 'Riyadh', 'Clerk', 'Alinma', '10200', '101000000015', 'SA030000000000101000000015', 'Saif Bader', '0503000115'],
            ['Mariam Jamal', 'sample.applicant16@example.test', 'TempPass@16', 'new', 'female', 'divorced', '1000000016', '1988-09-13', '', 'Al Rehab', 'Jeddah', '', '', '0501000116', '', 'Jeddah', 'Consultant', 'Self Employed', '12500', '101000000016', 'SA030000000000101000000016', 'Jamal Mariam', '0503000116'],
            ['Turki Nabil', 'sample.applicant17@example.test', '', 'renew', 'male', 'married', '1000000017', '1983-03-04', '2026-03-10', 'King Saud Rd', 'Riyadh', '0114000117', '', '0501000117', 'SEC', 'Riyadh', 'Manager', 'Saudi Electricity', '22300', '101000000017', 'SA030000000000101000000017', 'Nabil Turki', '0503000117'],
            ['Dana Hani', 'sample.applicant18@example.test', '', 'new', 'female', 'single', '1000000018', '1997-07-07', '', 'Al Murjan', 'Khobar', '', '', '0501000118', '', 'Khobar', '', '', '', '101000000018', 'SA030000000000101000000018', 'Hani Dana', '0503000118'],
            ['Omar Yasser', 'sample.applicant19@example.test', 'TempPass@19', 'resume', 'male', 'married', '1000000019', '1985-12-02', '2026-01-05', 'Al Worood', 'Riyadh', '', '', '0501000119', 'Riyadh Metro', 'Riyadh', 'Technician', 'RCRC', '14950', '101000000019', 'SA030000000000101000000019', 'Yasser Omar', '0503000119'],
            ['Shahad Rami', 'sample.applicant20@example.test', '', 'new', 'female', 'widowed', '1000000020', '1990-04-15', '', 'Al Yasmin', 'Riyadh', '', '', '0501000120', '', 'Riyadh', 'HR Specialist', 'Private Co', '13300', '101000000020', 'SA030000000000101000000020', 'Rami Shahad', '0503000120'],
        ];

        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }
}
