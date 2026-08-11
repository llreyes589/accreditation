<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Inspection flow for accreditation:
 *  - checklist_items: the master CART inspection checklist (sections A–I from
 *    CHECKLIST-OF-ACCREDITATION.xls), seeded once.
 *  - accreditation_inspections: the accreditor's captured answers per accreditation.
 * Status flow gains `requirements_completed` (between pending and inspection_scheduled)
 * and `inspected` (after the accreditor submits the checklist, before approval).
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('checklist_items', function (Blueprint $t) {
            $t->id();
            $t->char('section', 1);                                   // A–I
            $t->string('code')->nullable();                           // e.g. 1.0, a., i.
            $t->text('criterion');
            $t->boolean('is_major')->default(false);
            $t->text('notes_hint')->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
            $t->index('section');
        });

        Schema::create('accreditation_inspections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('accreditation_id')->constrained('accreditations')->cascadeOnDelete();
            $t->foreignId('accreditor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->date('inspection_scheduled_at')->nullable();
            $t->timestamp('conducted_at')->nullable();
            $t->string('status')->default('pending');                 // pending | submitted
            $t->json('answers')->nullable();                          // { itemId: {compliant, notes} }
            $t->timestamps();
            $t->softDeletes();
        });

        $this->seedChecklist();
    }

    public function down()
    {
        Schema::dropIfExists('accreditation_inspections');
        Schema::dropIfExists('checklist_items');
    }

    private function seedChecklist(): void
    {
        $items = [
            ['section' => 'A', 'code' => '1.0', 'criterion' => 'DOH License to Operate', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 1],
            ['section' => 'A', 'code' => '', 'criterion' => 'Tertiary Laboratory', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 2],
            ['section' => 'A', 'code' => '', 'criterion' => 'Hospital-based laboratory', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 3],
            ['section' => 'A', 'code' => '', 'criterion' => 'Licensed Sections:', 'is_major' => 0, 'notes_hint' => '', 'sort_order' => 4],
            ['section' => 'A', 'code' => '', 'criterion' => 'Hematology', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 5],
            ['section' => 'A', 'code' => '', 'criterion' => 'Clinical Microscopy', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 6],
            ['section' => 'A', 'code' => '', 'criterion' => 'Serology and Immunology', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 7],
            ['section' => 'A', 'code' => '', 'criterion' => 'Mircobiology', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 8],
            ['section' => 'A', 'code' => '', 'criterion' => 'Clinical Chemistry', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 9],
            ['section' => 'A', 'code' => '', 'criterion' => 'Blood bank with additional functions; In regions with a centralized blood bank, the residents must have a rotation there.', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 10],
            ['section' => 'A', 'code' => '', 'criterion' => 'Anatomic Pathology', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 11],
            ['section' => 'B', 'code' => '1.0', 'criterion' => 'There should be at least 3 pathologists, all board certified.', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 12],
            ['section' => 'B', 'code' => '', 'criterion' => 'The chair should be a fellow of the PSP in CP and/or AP.', 'is_major' => 1, 'notes_hint' => 'If you are not sure if the Chair is a fellow, ask Divine as she has a list. The Fellow certificate is also expected to be submitted with the other application documents.', 'sort_order' => 13],
            ['section' => 'B', 'code' => '', 'criterion' => 'The training officers should be:', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 14],
            ['section' => 'B', 'code' => '', 'criterion' => 'AP or AP-CP for Anatomic Pathology training', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 15],
            ['section' => 'B', 'code' => '', 'criterion' => 'CP or AP-CP for Clinical Pathology training', 'is_major' => 0, 'notes_hint' => '', 'sort_order' => 16],
            ['section' => 'B', 'code' => '', 'criterion' => 'There can be 1 training officer for both AP and CP Pathology training program', 'is_major' => 0, 'notes_hint' => '', 'sort_order' => 17],
            ['section' => 'B', 'code' => '', 'criterion' => 'The training officer should have undergone the PSP workshop for  training officers. (For future implementation)', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 18],
            ['section' => 'B', 'code' => '2.0', 'criterion' => 'For AP, ratio must be at least 1 AP consultant for every 3 residents.', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 19],
            ['section' => 'B', 'code' => '3.0', 'criterion' => 'For CP, ratio must be at least 1 CP consultant for every 3 residents', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 20],
            ['section' => 'B', 'code' => '4.0', 'criterion' => 'Consultant supervision on site must be evidenced by Record of regular Consultant-Resident interaction (like referrals, conferences, lectures) for Consultants who are part of the Training Program as: c.1. lecturer c.2. attendee', 'is_major' => 0, 'notes_hint' => 'Documents: Logbooks of referrals in CP, conferences, surgical reports/logbooks, referral logbooks for AP/proof of referral', 'sort_order' => 21],
            ['section' => 'B', 'code' => '5.0', 'criterion' => 'All consultant staff who are part of the Training Program (including visiting consultants) must show proof of continuing medical education - at least 15 CPD units of pathology seminars/workshops/conventions in a year as an attendee.', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 22],
            ['section' => 'B', 'code' => '6.0', 'criterion' => 'All sections/units involved in Pathology training should be headed by a pathologist certified in the corresponding specialty (AP/CP).', 'is_major' => 1, 'notes_hint' => 'One way of verifying this is by checking the result forms (current) or you can also backtrack/ organizational chart and admin appointment.', 'sort_order' => 23],
            ['section' => 'C', 'code' => '1.0', 'criterion' => 'A structured training program is in place. Documentary evidence includes, but not limited to the following:', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 24],
            ['section' => 'C', 'code' => '', 'criterion' => 'Institutional policy for recruitment, appointment, eligibility, and selection of residents', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 25],
            ['section' => 'C', 'code' => '', 'criterion' => 'Schedule of conferences* (intradepartmental, interdepartmental and interhospital), journal clubs, seminars, etc. with corresponding attendance logbook', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 26],
            ['section' => 'C', 'code' => '', 'criterion' => 'There should be at least 2 AP and 2 CP intradepartmental conferences** every month.', 'is_major' => 1, 'notes_hint' => 'yes, weekly.', 'sort_order' => 27],
            ['section' => 'C', 'code' => '', 'criterion' => 'Schedule of rotations in both AP and CP for all residents, including outside rotations', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 28],
            ['section' => 'C', 'code' => '', 'criterion' => 'There should be a total of 6 months rotation in both AP and CP per year. (Recommendation: maximum of 3 continuous months rotation per discipline)', 'is_major' => 1, 'notes_hint' => 'You can verify this by:  - Schedule of rotation - questions re the routine process in the section - frequency of qc running, howmany levels, how to troubleshoot', 'sort_order' => 29],
            ['section' => 'C', 'code' => '', 'criterion' => 'Evaluation schemes: performance evaluation (annual) and written/practical examinations (For inclusion in the PSP workshop for training officers)', 'is_major' => 1, 'notes_hint' => 'In-service exam result Test papers Recommendation: Mental Health (to ask re: VL and other forms of stress mngt)', 'sort_order' => 30],
            ['section' => 'C', 'code' => '2.0', 'criterion' => 'Training program manual based on current Training Program in AP & CP is reviewed, signed and dated by consultants', 'is_major' => 1, 'notes_hint' => 'Current is still the one released in 2013, the Competency based TP.', 'sort_order' => 31],
            ['section' => 'C', 'code' => '', 'criterion' => 'Training program must have been read by the residents and signed in conforme upon admission into the program', 'is_major' => 1, 'notes_hint' => 'Check the conforme sign.', 'sort_order' => 32],
            ['section' => 'D', 'code' => '1.0', 'criterion' => 'The following services must be available:', 'is_major' => 0, 'notes_hint' => '', 'sort_order' => 33],
            ['section' => 'D', 'code' => '', 'criterion' => 'Surgical Pathology', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 34],
            ['section' => 'D', 'code' => '', 'criterion' => 'Cytology (including imaging guided biopsies)', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 35],
            ['section' => 'D', 'code' => '', 'criterion' => 'Frozen Section', 'is_major' => 1, 'notes_hint' => 'Cryostat/separate FS logbook - 1 year if cryostat not working at time of visit', 'sort_order' => 36],
            ['section' => 'D', 'code' => '', 'criterion' => 'Autopsy', 'is_major' => 1, 'notes_hint' => 'table, water supply, saw', 'sort_order' => 37],
            ['section' => 'D', 'code' => '2.0', 'criterion' => 'There must be a hospital policy mandating submission of all inpatient surgical AND cytology specimens to the laboratory.', 'is_major' => 1, 'notes_hint' => 'Look for the document.', 'sort_order' => 38],
            ['section' => 'D', 'code' => '3.0', 'criterion' => 'Results, surgical and cytopathology slides, blocks, and photographs (if available) are filed for easy retrieval and for research purposes', 'is_major' => 0, 'notes_hint' => '', 'sort_order' => 39],
            ['section' => 'D', 'code' => '4.0', 'criterion' => 'The following minimum volume of work is required:', 'is_major' => 0, 'notes_hint' => '', 'sort_order' => 40],
            ['section' => 'D', 'code' => '', 'criterion' => 'Surgical pathology - 600 specimens (in-house)/resident/year', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 41],
            ['section' => 'D', 'code' => '', 'criterion' => '*Rotators must be taken into account and included in the computation.', 'is_major' => 0, 'notes_hint' => 'List of rotators with MOA/Proof of outside rotation countersigned by consultant in other constitution', 'sort_order' => 42],
            ['section' => 'D', 'code' => '', 'criterion' => 'Variety of cases (to follow later)', 'is_major' => 0, 'notes_hint' => 'Include in next revision.', 'sort_order' => 43],
            ['section' => 'D', 'code' => '', 'criterion' => 'Cytology (gynecologic and non-gynecologic) - 300 specimens/resident/year (to follow later)', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 44],
            ['section' => 'D', 'code' => '', 'criterion' => 'Frozen section - 10 cases/resident/year', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 45],
            ['section' => 'D', 'code' => '', 'criterion' => 'Autopsy - 10 cases/resident/4 years or training period (for residents who started end of 2016 & earlier) 5 cases/resident/4 years training period for residents who started 2017 onwards', 'is_major' => 0, 'notes_hint' => '5 cases/resident/4 year training period for residents  who started 2017; as required by the BOP.', 'sort_order' => 46],
            ['section' => 'D', 'code' => '', 'criterion' => '*Partial autopsies should include minimum of thoraco-abdominal organs.', 'is_major' => 0, 'notes_hint' => '', 'sort_order' => 47],
            ['section' => 'D', 'code' => '', 'criterion' => 'Immunohistochemical studies - 25  cases/resident/year', 'is_major' => 1, 'notes_hint' => 'Should be read in-house --IHC Logbook', 'sort_order' => 48],
            ['section' => 'D', 'code' => '', 'criterion' => 'Cases not stains are counted.', 'is_major' => 0, 'notes_hint' => '', 'sort_order' => 49],
            ['section' => 'D', 'code' => '', 'criterion' => 'Variety is encouraged.', 'is_major' => 0, 'notes_hint' => '', 'sort_order' => 50],
            ['section' => 'D', 'code' => '', 'criterion' => 'Increase in number of cases is applicable for new applicants for accreditation starting July 2018', 'is_major' => 0, 'notes_hint' => '', 'sort_order' => 51],
            ['section' => 'D', 'code' => '', 'criterion' => 'Molecular pathology rotation (optional)', 'is_major' => 0, 'notes_hint' => 'Encourage all institutions to open MolPath rotations', 'sort_order' => 52],
            ['section' => 'D', 'code' => '', 'criterion' => 'Proposed seminar/workshop on Molecular Pathology by PSP.', 'is_major' => 0, 'notes_hint' => '', 'sort_order' => 53],
            ['section' => 'D', 'code' => '5.0', 'criterion' => 'Rotations with another institution to supplement deficiencies in item 4 above should be covered by a Memorandum of Agreement or a certification.', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 54],
            ['section' => 'D', 'code' => '6.0', 'criterion' => 'The following equipment must be present in working condition:', 'is_major' => 0, 'notes_hint' => '', 'sort_order' => 55],
            ['section' => 'D', 'code' => '', 'criterion' => 'Microscopes (Minimum ratio should be 1 microscope for every 2 residents)', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 56],
            ['section' => 'D', 'code' => '', 'criterion' => 'Double or multi-header', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 57],
            ['section' => 'D', 'code' => '', 'criterion' => 'Microtome', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 58],
            ['section' => 'D', 'code' => '', 'criterion' => 'H&E and papanicolau stains', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 59],
            ['section' => 'D', 'code' => '', 'criterion' => 'Morgue with autopsy facility', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 60],
            ['section' => 'D', 'code' => '', 'criterion' => 'Cryostat', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 61],
            ['section' => 'D', 'code' => '', 'criterion' => 'Well-ventilated cutting room with at least 1 exhaust fan', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 62],
            ['section' => 'E', 'code' => '1.0', 'criterion' => 'The lab must have the following capabilities (this lists the minimum requirements necessary; no MOA with another laboratory will be accepted unless to supplement the minimum number required; the equipment must be in working condition, i.e., if machine is not working for more than 3 months in a year, this will be considered as if the tests are not being offered)', 'is_major' => 0, 'notes_hint' => '', 'sort_order' => 63],
            ['section' => 'E', 'code' => '', 'criterion' => 'Hematology', 'is_major' => 0, 'notes_hint' => 'with back up plan for all sections.', 'sort_order' => 64],
            ['section' => 'E', 'code' => '', 'criterion' => 'Automated hematology analyzer (5-part) with back-up machine', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 65],
            ['section' => 'E', 'code' => '', 'criterion' => 'Peripheral blood smear', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 66],
            ['section' => 'E', 'code' => '', 'criterion' => 'Coagulation Testing', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 67],
            ['section' => 'E', 'code' => '', 'criterion' => 'Malarial smear', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 68],
            ['section' => 'E', 'code' => '', 'criterion' => 'ESR', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 69],
            ['section' => 'E', 'code' => '', 'criterion' => 'Clinical Microscopy', 'is_major' => 0, 'notes_hint' => '', 'sort_order' => 70],
            ['section' => 'E', 'code' => '', 'criterion' => 'Urinalysis', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 71],
            ['section' => 'E', 'code' => '', 'criterion' => 'Fecalysis', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 72],
            ['section' => 'E', 'code' => '', 'criterion' => 'Semenalysis', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 73],
            ['section' => 'E', 'code' => '', 'criterion' => 'Other body fluids examination', 'is_major' => 1, 'notes_hint' => 'CSF Pleural Peritoneal ; see logbook.', 'sort_order' => 74],
            ['section' => 'E', 'code' => '', 'criterion' => 'Serology and Immunology', 'is_major' => 0, 'notes_hint' => '', 'sort_order' => 75],
            ['section' => 'E', 'code' => '', 'criterion' => 'Hepatitis profile (minimum: HbsAg, anti-HBs, Hbe, anti-Hbe and anti-Hbc)', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 76],
            ['section' => 'E', 'code' => '', 'criterion' => 'HIV testing', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 77],
            ['section' => 'E', 'code' => '', 'criterion' => 'Thyroid function tests (minimum: TSH, T3 and T4)', 'is_major' => 1, 'notes_hint' => 'Should be under laboratory supervision and residents must be rotating in the section, supervised by a Pathologist.', 'sort_order' => 78],
            ['section' => 'E', 'code' => '', 'criterion' => 'iv. Pregnancy test', 'is_major' => 0, 'notes_hint' => '', 'sort_order' => 79],
            ['section' => 'E', 'code' => '', 'criterion' => 'Tumor markers, note down  test available', 'is_major' => 1, 'notes_hint' => 'At least 1 tumor marker', 'sort_order' => 80],
            ['section' => 'E', 'code' => '', 'criterion' => 'Microbiology', 'is_major' => 0, 'notes_hint' => '', 'sort_order' => 81],
            ['section' => 'E', 'code' => '', 'criterion' => 'Biosafety Cabinet', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 82],
            ['section' => 'E', 'code' => '', 'criterion' => 'Gram Stain', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 83],
            ['section' => 'E', 'code' => '', 'criterion' => 'KOH', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 84],
            ['section' => 'E', 'code' => '', 'criterion' => 'AFB smear', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 85],
            ['section' => 'E', 'code' => '', 'criterion' => 'Culture and sensivitiy', 'is_major' => 1, 'notes_hint' => 'Gram (+) and Gram (-) organisms; check results logbook or LIS', 'sort_order' => 86],
            ['section' => 'E', 'code' => '', 'criterion' => 'Clinical Chemistry', 'is_major' => 0, 'notes_hint' => '', 'sort_order' => 87],
            ['section' => 'E', 'code' => '', 'criterion' => 'Lipid profile (cholesterol, triglyceride HDL and VDL)', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 88],
            ['section' => 'E', 'code' => '', 'criterion' => 'BUN and creatinine', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 89],
            ['section' => 'E', 'code' => '', 'criterion' => 'Glucose AND Hba1c', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 90],
            ['section' => 'E', 'code' => '', 'criterion' => 'Liver function tests (minimum: SGOT, SGPT and TB/IB/DB )', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 91],
            ['section' => 'E', 'code' => '', 'criterion' => 'Electrolytes (minimum: sodium and potassium)', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 92],
            ['section' => 'E', 'code' => '', 'criterion' => 'Total protein, albumin and globulin', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 93],
            ['section' => 'E', 'code' => '', 'criterion' => 'Cardiac markers (minimum: troponin)', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 94],
            ['section' => 'E', 'code' => '', 'criterion' => 'POCT policy and supervision', 'is_major' => 1, 'notes_hint' => 'administered & operated by the lab', 'sort_order' => 95],
            ['section' => 'E', 'code' => '', 'criterion' => 'Blood Bank', 'is_major' => 0, 'notes_hint' => '', 'sort_order' => 96],
            ['section' => 'E', 'code' => '', 'criterion' => 'Blood typing', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 97],
            ['section' => 'E', 'code' => '', 'criterion' => 'Crossmatching', 'is_major' => 1, 'notes_hint' => 'tube method', 'sort_order' => 98],
            ['section' => 'E', 'code' => '', 'criterion' => 'Serological screening (Hepatitis B & C, HIV, malaria and syphilis)', 'is_major' => 1, 'notes_hint' => 'ELISA for HIV testing', 'sort_order' => 99],
            ['section' => 'E', 'code' => '', 'criterion' => 'Equipment for processing', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 100],
            ['section' => 'E', 'code' => '', 'criterion' => '1)  Refrigerated centrifuge', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 101],
            ['section' => 'E', 'code' => '', 'criterion' => '2)  Plasma freezer', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 102],
            ['section' => 'E', 'code' => '', 'criterion' => '3)  Blood bank refrigerator', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 103],
            ['section' => 'E', 'code' => '', 'criterion' => '4)  Plasma thawer', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 104],
            ['section' => 'E', 'code' => '', 'criterion' => '5)  Platelet agitator', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 105],
            ['section' => 'E', 'code' => '', 'criterion' => '6)  Plasma extractor', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 106],
            ['section' => 'E', 'code' => '', 'criterion' => '7)  Tube sealer', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 107],
            ['section' => 'E', 'code' => '', 'criterion' => 'Donor screening and bleeding', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 108],
            ['section' => 'E', 'code' => '', 'criterion' => 'Drug testing lab (or rotation outside in an institution with an accredited training program with proper documentation)', 'is_major' => 0, 'notes_hint' => 'This should be included in the residents rotation. There should be a LTO or a MOA with an outside DT lab. Check if senior resident has rotated in DT and if resident can answer related questions.', 'sort_order' => 109],
            ['section' => 'E', 'code' => '', 'criterion' => 'Water testing lab (optional)', 'is_major' => 0, 'notes_hint' => '', 'sort_order' => 110],
            ['section' => 'E', 'code' => '2.0', 'criterion' => 'There must be a minimum of 20,000 tests in CP per resident per year.', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 111],
            ['section' => 'E', 'code' => '3.0', 'criterion' => 'In institutions with no designated physician to screen donors, a hospital policy and duty roster should be in place, reflecting sharing of donor screening responsibilities with all clinical departments with no prejudice towards pathology residents. Duty as donor screener should not exceed more than 7 days per Blood Bank rotation per year.', 'is_major' => 1, 'notes_hint' => '', 'sort_order' => 112],
            ['section' => 'F', 'code' => '1.0', 'criterion' => 'Research schedule (including case reports) and/or timeline of research activities.', 'is_major' => 0, 'notes_hint' => 'Check portfoilio and note in what stage the research is already.', 'sort_order' => 113],
            ['section' => 'F', 'code' => '2.0', 'criterion' => 'Referral logbook in CP to include, but not limited to, the following data: Patient name/age/sex/brief description of problem or issue/action taken/ resident in charge/referring staff', 'is_major' => 1, 'notes_hint' => '- referral of MT to resident to H147; documented; proof that consultant checks the referrals regularly or periodically. - Hema, BB, blood transfusioon reactions, panic values, lab management, troubleshooting', 'sort_order' => 114],
        ];

        foreach ($items as $it) {
            DB::table('checklist_items')->insert(array_merge($it, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }
};
