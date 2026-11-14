<?php
namespace CAS\ResearchDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

// Timeline seeded from RES402_Research_Project_Timeline (Updated for 2025/2026). Edit in the app (DAA > Timeline) rather than here.
return [
  ['seq'=>1,'title'=>'1. Proposal Writing & Schedule','kind'=>'publish','form_no'=>0,'ack_role'=>'','deadline_from'=>'2026-07-31','deadline_to'=>null,'t0_text'=>'August 7 2026','roles'=>'student,supervisor,daa,pl','eval_files'=>0,'pl_download'=>0,
    'txt'=>['Document viewing option action: View or Download option',
      'Document viewing option action: View or Download option',
      'Document uploading option: Yes (PDF, Docx),
Receiver selection option (can select more than one): Supervisor, Programme Leader, Student, Evaluator',
      'Document viewing option: View or Download option
PL has the option to select the view by department',
      'Do not show this activity to evaluator']],
  ['seq'=>2,'title'=>'2. Identifying topics / Assigning Research Project supervisor','kind'=>'supervisor_select','form_no'=>0,'ack_role'=>'','deadline_from'=>'2026-08-08','deadline_to'=>null,'t0_text'=>'August 15 2026','roles'=>'student,supervisor,daa,pl','eval_files'=>0,'pl_download'=>0,
    'txt'=>['Students can select among list of departments and Supervisors.
Action: Submitted.
No need for upload file option',
      'Supervisors can see the list of students along with the topics.
Action against the list : Accepted, Not Not accepted.
There should be option for Comments.',
      'DAA  can see and view the list of students and supervisor match and the agreed topic.
DAA has the option to assign supervisors (from a list supervisors) to students, if they are not accepted by any',
      'PL  can see and view the list of students and supervisor match and the agreed topic.',
      'Do not show this activity to evaluator']],
  ['seq'=>3,'title'=>'Finalization of Research Project topic','kind'=>'topic_final','form_no'=>0,'ack_role'=>'','deadline_from'=>'2026-09-22','deadline_to'=>null,'t0_text'=>'','roles'=>'student,supervisor,daa,pl','eval_files'=>0,'pl_download'=>0,
    'txt'=>['Action: Submit and completed
Space for writing the Finalized topic
No need for upload file option',
      'Submited topic must be visible to the supervisor
Receive the topic
Action: Approved and completed',
      'Submited topic must be visible to the DAA',
      'Submited topic must be visible to the Programme Leader',
      'Do not show this activity to evaluator']],
  ['seq'=>4,'title'=>'Submission of Research Proposal (Draft) to Supervisor','kind'=>'student_file','form_no'=>0,'ack_role'=>'supervisor','deadline_from'=>'2026-10-07','deadline_to'=>null,'t0_text'=>'','roles'=>'student,supervisor,daa,pl','eval_files'=>0,'pl_download'=>0,
    'txt'=>['Need for upload file option
Action: Submit and completed',
      'The document must be received by supervisors
Action: Received and completed',
      'Submited file must be visible to the DAA for view and download',
      'Submited  file must be visible to the Programme Leader for view',
      'Do not show this activity to evaluator']],
  ['seq'=>5,'title'=>'Supervisor to return draft proposal to students','kind'=>'supervisor_file','form_no'=>0,'ack_role'=>'student','deadline_from'=>'2026-10-14','deadline_to'=>null,'t0_text'=>'','roles'=>'student,supervisor,daa,pl','eval_files'=>0,'pl_download'=>0,
    'txt'=>['The document must be received by students
Action: Received and completed',
      'Need for upload file option
Action: Submit and completed',
      'Submited file must be visible to the DAA for view and download',
      'Submited  file must be visible to the Programme Leader for view',
      'Do not show this activity to evaluator']],
  ['seq'=>6,'title'=>'Final Proposal Submission to Supervisor','kind'=>'student_file','form_no'=>0,'ack_role'=>'supervisor','deadline_from'=>'2026-10-18','deadline_to'=>null,'t0_text'=>'','roles'=>'student,supervisor,daa,pl','eval_files'=>0,'pl_download'=>0,
    'txt'=>['Action: Submit and completed
Need for upload file option',
      'The document must be received by supervisors
Action: Received and completed',
      'Submited file must be visible to the DAA for view and download',
      'Submited  file must be visible to the Programme Leader for view',
      'Do not show this activity to evaluator']],
  ['seq'=>7,'title'=>'Student Submit Supervisor Approved Research Project proposal','kind'=>'student_file','form_no'=>0,'ack_role'=>'pl','deadline_from'=>'2026-10-19','deadline_to'=>null,'t0_text'=>'','roles'=>'student,supervisor,daa,pl','eval_files'=>1,'pl_download'=>1,
    'txt'=>['Action: Submit and completed
Need for upload file option',
      'Submited  file must be visible to the Supervisor for view',
      'Submited file must be visible to the DAA for view and download',
      'The document must be received by Programme Leader
Action: Received and completed',
      'Do not show this activity to evaluator']],
  ['seq'=>8,'title'=>'Review of the proposal / Defense','kind'=>'defense','form_no'=>4,'ack_role'=>'','deadline_from'=>'2026-10-20','deadline_to'=>'2026-10-24','t0_text'=>'','roles'=>'student,supervisor,daa,pl,evaluator','eval_files'=>0,'pl_download'=>0,
    'txt'=>['Action: Presented and completed or did not present',
      'Supervisor can view the status',
      'DAA can view the status',
      'The marks submitted  can be viewed by Programme Leader in a table form (Student, Title of work, Marks).
Action: Download (download can be done after selecting department',
      'Evaluators send the marks to programme leaders using Form 4 (attached file).
Action: Click the mark enter option
The action should open in another window where the form is displayed with option for mark entry.
Action: Submit']],
  ['seq'=>9,'title'=>'Return proposal','kind'=>'student_text','form_no'=>0,'ack_role'=>'supervisor','deadline_from'=>'2026-10-27','deadline_to'=>null,'t0_text'=>'','roles'=>'student,supervisor,daa,pl','eval_files'=>0,'pl_download'=>0,
    'txt'=>['Space for sharing comments received with the supervisors
Action: Share and completed',
      'The comments must be received by supervisors
Action: Received and completed',
      'Submited comment must be visible to the DAA for view and download',
      'Submited  comment must be visible to the Programme Leader for view',
      'Do not show this activity to evaluator']],
  ['seq'=>10,'title'=>'Students to incorporate review comments and Final proposal submission','kind'=>'student_file','form_no'=>0,'ack_role'=>'supervisor','deadline_from'=>'2026-11-06','deadline_to'=>null,'t0_text'=>'','roles'=>'student,supervisor,daa,pl','eval_files'=>0,'pl_download'=>0,
    'txt'=>['Incorporate review comments and submit the final proposal to supervisor
Need for upload file option',
      'The document must be received by supervisors
Action: Received and completed',
      'Submited comment must be visible to the DAA for view and download',
      'Submited  comment must be visible to the Programme Leader for view',
      'Do not show this activity to evaluator']],
  ['seq'=>11,'title'=>'1st Draft of research project submission','kind'=>'student_file','form_no'=>0,'ack_role'=>'','deadline_from'=>'2027-04-01','deadline_to'=>null,'t0_text'=>'Wednesday','roles'=>'student,supervisor,daa,pl','eval_files'=>0,'pl_download'=>0,
    'txt'=>['Action: Submit and completed
Need for upload file option',
      'Submited  file must be visible to the Supervisor for view',
      'Submited file must be visible to the DAA for view and download',
      'Submited  comment must be visible to the Programme Leader for view',
      'Do not show this activity to evaluator']],
  ['seq'=>12,'title'=>'Return the research project','kind'=>'supervisor_file','form_no'=>0,'ack_role'=>'student','deadline_from'=>'2027-04-10','deadline_to'=>null,'t0_text'=>'Friday','roles'=>'student,supervisor,daa,pl','eval_files'=>0,'pl_download'=>0,
    'txt'=>['The document must be received by students
Action: Received and completed
Option to write comments',
      'Need for upload file option
Action: Submit and completed',
      'Submited file must be visible to the DAA for view and download',
      'Submited  file must be visible to the Programme Leader for view',
      'Do not show this activity to evaluator']],
  ['seq'=>13,'title'=>'2nd Draft submission','kind'=>'student_file','form_no'=>0,'ack_role'=>'','deadline_from'=>'2027-04-20','deadline_to'=>null,'t0_text'=>'Monday','roles'=>'student,supervisor,daa,pl','eval_files'=>0,'pl_download'=>0,
    'txt'=>['Action: Submit and completed
Need for upload file option',
      'Submited  file must be visible to the Supervisor for view',
      'Submited file must be visible to the DAA for view and download',
      'Submited  comment must be visible to the Programme Leader for view',
      'Do not show this activity to evaluator']],
  ['seq'=>14,'title'=>'Review of the 2nd draft','kind'=>'supervisor_file','form_no'=>0,'ack_role'=>'student','deadline_from'=>'2027-04-30','deadline_to'=>null,'t0_text'=>'Thursday','roles'=>'student,supervisor,daa,pl','eval_files'=>0,'pl_download'=>0,
    'txt'=>['The document must be received by students
Action: Received and completed
Option to write comments',
      'Need for upload file option
Action: Submit and completed',
      'Submited file must be visible to the DAA for view and download',
      'Submited  file must be visible to the Programme Leader for view',
      'Do not show this activity to evaluator']],
  ['seq'=>15,'title'=>'(3rd Draft submission)','kind'=>'student_file','form_no'=>0,'ack_role'=>'','deadline_from'=>'2027-05-11','deadline_to'=>null,'t0_text'=>'Monday','roles'=>'student,supervisor,daa,pl','eval_files'=>2,'pl_download'=>0,
    'txt'=>['Action: Submit and completed
Need for upload file option',
      'Submited  file must be visible to the Supervisor for view',
      'Submited file must be visible to the DAA for view and download',
      'Submited  comment must be visible to the Programme Leader for view and further action',
      'Do not show this activity to evaluator']],
  ['seq'=>16,'title'=>'Distribution to internal evaluators','kind'=>'assign_evaluators','form_no'=>0,'ack_role'=>'evaluator','deadline_from'=>'2027-05-11','deadline_to'=>null,'t0_text'=>'Monday','roles'=>'student,supervisor,daa,pl,evaluator','eval_files'=>0,'pl_download'=>0,
    'txt'=>['Students see the status: Send to evaluators',
      'Supervisors see the status: Send to evaluators',
      'DAA see the status: Send to evaluators',
      'Option to select among the evaluators 2 names per project
Action: Select and submit',
      'Evaluators can see the list of assigned work.
Action: Received']],
  ['seq'=>17,'title'=>'Evaluators to return the research project','kind'=>'evaluate','form_no'=>6,'ack_role'=>'','deadline_from'=>'2027-05-25','deadline_to'=>null,'t0_text'=>'Monday','roles'=>'student,supervisor,daa,pl,evaluator','eval_files'=>0,'pl_download'=>0,
    'txt'=>['Students see the status: Returned to Programme Leader',
      'Supervisors see the status: Returned to Programme Leader',
      'DAA see the status: Returned to Programme Leader',
      'The marks submitted  can be viewed by Programme Leader in a table form (Student, Title of work, Marks).
Action: Download (download can be done after selecting department',
      'Evaluators send the marks to programme leaders using Form 6 (attached file).
Action: Click the mark enter option.
The action should open in another window.
The action should open in another window where the form is displayed with option for mark entry.
Action: Submit']],
  ['seq'=>18,'title'=>'Defense','kind'=>'defense','form_no'=>7,'ack_role'=>'','deadline_from'=>'2027-06-02','deadline_to'=>'2027-06-03','t0_text'=>'Tuesday and Wednesday','roles'=>'student,supervisor,daa,pl','eval_files'=>0,'pl_download'=>0,
    'txt'=>['Action: Presented and completed or did not present',
      'Supervisor can view the status',
      'DAA can view the status',
      'Programme Leader will enter the marks in a table form 7 (Student, Title of work, Marks).
Programme leader can have a option to choose “ enter marks”
The action should open in another window where the form is displayed with option for mark entry.
Action: Submit',
      'Do not show this activity to evaluator']],
  ['seq'=>19,'title'=>'Research Project submission (Final)','kind'=>'student_file','form_no'=>0,'ack_role'=>'','deadline_from'=>'2027-06-15','deadline_to'=>null,'t0_text'=>'Monday','roles'=>'student,supervisor,daa,pl','eval_files'=>0,'pl_download'=>1,
    'txt'=>['Action: Submit and completed
Need for upload file option',
      'Submited  file must be visible to the Supervisor for view',
      'Submited file must be visible to the DAA for view and download',
      'Submited  comment must be visible to the Programme Leader for view and download',
      'Do not show this activity to evaluator']],
  ['seq'=>20,'title'=>'Article submission (1st Draft)','kind'=>'student_file','form_no'=>0,'ack_role'=>'','deadline_from'=>'2027-06-19','deadline_to'=>null,'t0_text'=>'Friday','roles'=>'student,supervisor,daa,pl','eval_files'=>0,'pl_download'=>0,
    'txt'=>['Action: Submit and completed
Need for upload file option',
      'Submited  file must be visible to the Supervisor for view',
      'Submited file must be visible to the DAA for view and download',
      'Submited  comment must be visible to the Programme Leader for view',
      'Do not show this activity to evaluator']],
  ['seq'=>21,'title'=>'Return articles','kind'=>'supervisor_file','form_no'=>0,'ack_role'=>'student','deadline_from'=>'2027-06-22','deadline_to'=>null,'t0_text'=>'Monday','roles'=>'student,supervisor,daa,pl','eval_files'=>0,'pl_download'=>0,
    'txt'=>['The document must be received by students
Action: Received and completed
Option to write comments',
      'Need for upload file option
Action: Submit and completed',
      'Submited file must be visible to the DAA for view and download',
      'Submited  file must be visible to the Programme Leader for view',
      'Do not show this activity to evaluator']],
  ['seq'=>22,'title'=>'Article submission (Final)','kind'=>'student_file','form_no'=>0,'ack_role'=>'','deadline_from'=>'2027-06-23','deadline_to'=>null,'t0_text'=>'Tuesday','roles'=>'student,supervisor,daa,pl','eval_files'=>0,'pl_download'=>0,
    'txt'=>['Action: Submit and completed
Need for upload file option',
      'Submited  file must be visible to the Supervisor for view',
      'Submited file must be visible to the DAA for view and download',
      'Submited  comment must be visible to the Programme Leader for view',
      'Do not show this activity to evaluator']],
  ['seq'=>23,'title'=>'Submission of grades','kind'=>'grades','form_no'=>0,'ack_role'=>'','deadline_from'=>'2027-06-24','deadline_to'=>null,'t0_text'=>'Wednesday','roles'=>'student,supervisor,daa,pl','eval_files'=>0,'pl_download'=>0,
    'txt'=>['Students see the status: Procedure completed.',
      'Supervisor send the marks to programme leaders using Form 5 and 8 seperately (attached file).
Option to select: Research Process, Article Evaluation
Action: Click the mark enter option and submit against both the actions.
The action should open in another window.
The action should open in another window where the form is displayed with option for mark entry.
Action: Submit',
      'The marks submitted  can be viewed by Programme Leader in a table form (Student, Title of work, Marks).',
      'The marks submitted  can be viewed by Programme Leader in a table form (Student, Title of work, Marks).
Action: Download (download can be done after selecting department',
      'Do not show this activity to evaluator']],
];
