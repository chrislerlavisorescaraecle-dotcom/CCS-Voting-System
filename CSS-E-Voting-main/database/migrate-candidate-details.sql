-- Apply this migration to existing css_voting_system databases that predate
-- candidate student ID, year level, and course fields.
ALTER TABLE `candidates`
  ADD COLUMN IF NOT EXISTS `student_id` varchar(30) DEFAULT NULL AFTER `fullname`,
  ADD COLUMN IF NOT EXISTS `year_level` varchar(20) DEFAULT NULL AFTER `student_id`,
  ADD COLUMN IF NOT EXISTS `course` varchar(100) DEFAULT NULL AFTER `year_level`;
