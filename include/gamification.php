<?php
/**
 * Stimma - Gamification System
 *
 * Hanterar streaks, badges, XP och certifikat
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

// XP-värden för olika aktiviteter
define('XP_LESSON_COMPLETE', 25);
define('XP_QUIZ_CORRECT', 15);
define('XP_QUIZ_FIRST_TRY', 10);
define('XP_COURSE_COMPLETE', 100);
define('XP_STREAK_BONUS', 5); // Bonus per dag i streak

/**
 * Hämta eller skapa user_stats för en användare
 */
function getUserStats($userId) {
    $stats = queryOne("SELECT * FROM " . DB_DATABASE . ".user_stats WHERE user_id = ?", [$userId]);

    if (!$stats) {
        execute("INSERT INTO " . DB_DATABASE . ".user_stats (user_id) VALUES (?)", [$userId]);
        $stats = queryOne("SELECT * FROM " . DB_DATABASE . ".user_stats WHERE user_id = ?", [$userId]);
    }

    return $stats;
}

/**
 * Uppdatera streak för en användare
 * Returnerar array med streak-info och eventuellt nya badges
 */
function updateStreak($userId) {
    $stats = getUserStats($userId);
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    $lastActivity = $stats['last_activity_date'];
    $currentStreak = (int)$stats['current_streak'];
    $longestStreak = (int)$stats['longest_streak'];

    $newBadges = [];
    $streakBroken = false;

    if ($lastActivity === $today) {
        // Redan loggat aktivitet idag
        return [
            'current_streak' => $currentStreak,
            'longest_streak' => $longestStreak,
            'new_badges' => [],
            'streak_broken' => false
        ];
    }

    if ($lastActivity === $yesterday) {
        // Fortsätt streak
        $currentStreak++;
    } elseif ($lastActivity === null || $lastActivity < $yesterday) {
        // Bruten streak eller ny användare
        if ($currentStreak > 0) {
            $streakBroken = true;
        }
        $currentStreak = 1;
    }

    // Uppdatera längsta streak
    if ($currentStreak > $longestStreak) {
        $longestStreak = $currentStreak;
    }

    // Uppdatera user_stats
    execute("UPDATE " . DB_DATABASE . ".user_stats
             SET current_streak = ?, longest_streak = ?, last_activity_date = ?
             WHERE user_id = ?",
            [$currentStreak, $longestStreak, $today, $userId]);

    // Logga daglig aktivitet
    execute("INSERT INTO " . DB_DATABASE . ".daily_activity (user_id, activity_date)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE lessons_completed = lessons_completed",
            [$userId, $today]);

    // Kontrollera streak-badges
    $newBadges = checkAndAwardBadges($userId, 'streak', $currentStreak);

    return [
        'current_streak' => $currentStreak,
        'longest_streak' => $longestStreak,
        'new_badges' => $newBadges,
        'streak_broken' => $streakBroken
    ];
}

/**
 * Lägg till XP till en användare
 */
function addXP($userId, $amount, $reason = '') {
    $stats = getUserStats($userId);
    $newTotal = (int)$stats['total_xp'] + $amount;

    execute("UPDATE " . DB_DATABASE . ".user_stats SET total_xp = ? WHERE user_id = ?",
            [$newTotal, $userId]);

    // Uppdatera daglig XP
    $today = date('Y-m-d');
    execute("INSERT INTO " . DB_DATABASE . ".daily_activity (user_id, activity_date, xp_earned)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE xp_earned = xp_earned + ?",
            [$userId, $today, $amount, $amount]);

    // Kontrollera XP-badges
    $newBadges = checkAndAwardBadges($userId, 'xp', $newTotal);

    return [
        'total_xp' => $newTotal,
        'added' => $amount,
        'new_badges' => $newBadges
    ];
}

/**
 * Registrera slutförd lektion
 */
function recordLessonCompletion($userId, $lessonId, $quizCorrect = false, $firstTry = false) {
    $stats = getUserStats($userId);

    // Uppdatera streak
    $streakInfo = updateStreak($userId);

    // Beräkna XP
    $xpEarned = XP_LESSON_COMPLETE;
    if ($quizCorrect) {
        $xpEarned += XP_QUIZ_CORRECT;
        if ($firstTry) {
            $xpEarned += XP_QUIZ_FIRST_TRY;
        }
    }

    // Lägg till streak-bonus
    $xpEarned += min($streakInfo['current_streak'], 30) * XP_STREAK_BONUS;

    // Uppdatera statistik
    $lessonsCompleted = (int)$stats['lessons_completed'] + 1;
    $quizzesPassed = (int)$stats['quizzes_passed'] + ($quizCorrect ? 1 : 0);

    execute("UPDATE " . DB_DATABASE . ".user_stats
             SET lessons_completed = ?, quizzes_passed = ?
             WHERE user_id = ?",
            [$lessonsCompleted, $quizzesPassed, $userId]);

    // Uppdatera daglig aktivitet
    $today = date('Y-m-d');
    execute("UPDATE " . DB_DATABASE . ".daily_activity
             SET lessons_completed = lessons_completed + 1
             WHERE user_id = ? AND activity_date = ?",
            [$userId, $today]);

    // Lägg till XP
    $xpInfo = addXP($userId, $xpEarned, "Lektion slutförd: $lessonId");

    // Kontrollera lesson-badges
    $lessonBadges = checkAndAwardBadges($userId, 'lessons', $lessonsCompleted);

    // Kontrollera special badges (tid på dygnet)
    $specialBadges = checkSpecialBadges($userId);

    // Samla alla nya badges
    $allNewBadges = array_merge(
        $streakInfo['new_badges'],
        $xpInfo['new_badges'],
        $lessonBadges,
        $specialBadges
    );

    return [
        'xp_earned' => $xpEarned,
        'total_xp' => $xpInfo['total_xp'],
        'current_streak' => $streakInfo['current_streak'],
        'lessons_completed' => $lessonsCompleted,
        'new_badges' => $allNewBadges,
        'streak_broken' => $streakInfo['streak_broken']
    ];
}

/**
 * Registrera slutförd kurs och skapa certifikat
 */
function recordCourseCompletion($userId, $courseId) {
    $stats = getUserStats($userId);

    // Kontrollera om kursen redan är markerad som slutförd
    $existingCert = queryOne("SELECT id FROM " . DB_DATABASE . ".certificates
                              WHERE user_id = ? AND course_id = ?",
                             [$userId, $courseId]);

    if ($existingCert) {
        return ['already_completed' => true, 'certificate_id' => $existingCert['id']];
    }

    // Hämta kursinformation
    $course = queryOne("SELECT title FROM " . DB_DATABASE . ".courses WHERE id = ?", [$courseId]);
    $user = queryOne("SELECT name, email FROM " . DB_DATABASE . ".users WHERE id = ?", [$userId]);

    if (!$course || !$user) {
        return ['error' => 'Kurs eller användare hittades inte'];
    }

    // Generera certifikatnummer
    $certNumber = 'STIMMA-' . date('Y') . '-' . str_pad($userId, 4, '0', STR_PAD_LEFT) . '-' . str_pad($courseId, 4, '0', STR_PAD_LEFT) . '-' . strtoupper(substr(md5(time()), 0, 6));

    // Skapa certifikat
    $certId = execute("INSERT INTO " . DB_DATABASE . ".certificates
                       (user_id, course_id, certificate_number, course_title, user_name, completion_date)
                       VALUES (?, ?, ?, ?, ?, CURDATE())",
                      [$userId, $courseId, $certNumber, $course['title'], $user['name'] ?: $user['email']]);

    // Uppdatera statistik
    $coursesCompleted = (int)$stats['courses_completed'] + 1;
    execute("UPDATE " . DB_DATABASE . ".user_stats SET courses_completed = ? WHERE user_id = ?",
            [$coursesCompleted, $userId]);

    // Lägg till XP för kursavslut
    $xpInfo = addXP($userId, XP_COURSE_COMPLETE, "Kurs slutförd: $courseId");

    // Kontrollera course-badges
    $courseBadges = checkAndAwardBadges($userId, 'courses', $coursesCompleted);

    return [
        'certificate_id' => $certId,
        'certificate_number' => $certNumber,
        'xp_earned' => XP_COURSE_COMPLETE,
        'courses_completed' => $coursesCompleted,
        'new_badges' => array_merge($xpInfo['new_badges'], $courseBadges)
    ];
}

/**
 * Kontrollera och dela ut badges baserat på krav
 */
function checkAndAwardBadges($userId, $requirementType, $currentValue) {
    $newBadges = [];

    // Hämta alla badges som matchar typen och som användaren inte redan har
    $badges = query("SELECT b.* FROM " . DB_DATABASE . ".badges b
                     WHERE b.requirement_type = ?
                     AND b.requirement_value <= ?
                     AND b.id NOT IN (SELECT badge_id FROM " . DB_DATABASE . ".user_badges WHERE user_id = ?)
                     ORDER BY b.requirement_value ASC",
                    [$requirementType, $currentValue, $userId]);

    foreach ($badges as $badge) {
        // Dela ut badge
        execute("INSERT IGNORE INTO " . DB_DATABASE . ".user_badges (user_id, badge_id) VALUES (?, ?)",
                [$userId, $badge['id']]);

        // Lägg till XP-belöning om badge har en
        if ($badge['xp_reward'] > 0) {
            execute("UPDATE " . DB_DATABASE . ".user_stats
                     SET total_xp = total_xp + ?
                     WHERE user_id = ?",
                    [$badge['xp_reward'], $userId]);
        }

        $newBadges[] = $badge;
    }

    return $newBadges;
}

/**
 * Kontrollera special badges (tid på dygnet etc.)
 */
function checkSpecialBadges($userId) {
    $newBadges = [];
    $hour = (int)date('H');

    // Morgonfågel (före 07:00)
    if ($hour < 7) {
        $badge = queryOne("SELECT b.* FROM " . DB_DATABASE . ".badges b
                          WHERE b.slug = 'early_bird'
                          AND b.id NOT IN (SELECT badge_id FROM " . DB_DATABASE . ".user_badges WHERE user_id = ?)",
                         [$userId]);
        if ($badge) {
            execute("INSERT IGNORE INTO " . DB_DATABASE . ".user_badges (user_id, badge_id) VALUES (?, ?)",
                    [$userId, $badge['id']]);
            if ($badge['xp_reward'] > 0) {
                execute("UPDATE " . DB_DATABASE . ".user_stats SET total_xp = total_xp + ? WHERE user_id = ?",
                        [$badge['xp_reward'], $userId]);
            }
            $newBadges[] = $badge;
        }
    }

    // Nattuggla (efter 22:00)
    if ($hour >= 22) {
        $badge = queryOne("SELECT b.* FROM " . DB_DATABASE . ".badges b
                          WHERE b.slug = 'night_owl'
                          AND b.id NOT IN (SELECT badge_id FROM " . DB_DATABASE . ".user_badges WHERE user_id = ?)",
                         [$userId]);
        if ($badge) {
            execute("INSERT IGNORE INTO " . DB_DATABASE . ".user_badges (user_id, badge_id) VALUES (?, ?)",
                    [$userId, $badge['id']]);
            if ($badge['xp_reward'] > 0) {
                execute("UPDATE " . DB_DATABASE . ".user_stats SET total_xp = total_xp + ? WHERE user_id = ?",
                        [$badge['xp_reward'], $userId]);
            }
            $newBadges[] = $badge;
        }
    }

    return $newBadges;
}

/**
 * Hämta alla badges för en användare
 */
function getUserBadges($userId) {
    return query("SELECT b.*, ub.earned_at
                  FROM " . DB_DATABASE . ".user_badges ub
                  JOIN " . DB_DATABASE . ".badges b ON ub.badge_id = b.id
                  WHERE ub.user_id = ?
                  ORDER BY ub.earned_at DESC",
                 [$userId]);
}

/**
 * Hämta alla tillgängliga badges med status för en användare
 */
function getAllBadgesWithStatus($userId) {
    return query("SELECT b.*,
                         CASE WHEN ub.id IS NOT NULL THEN 1 ELSE 0 END as earned,
                         ub.earned_at
                  FROM " . DB_DATABASE . ".badges b
                  LEFT JOIN " . DB_DATABASE . ".user_badges ub ON b.id = ub.badge_id AND ub.user_id = ?
                  ORDER BY b.sort_order ASC",
                 [$userId]);
}

/**
 * Hämta certifikat för en användare
 */
function getUserCertificates($userId) {
    return query("SELECT c.*, co.image_url as course_image, co.certificate_image_url
                  FROM " . DB_DATABASE . ".certificates c
                  LEFT JOIN " . DB_DATABASE . ".courses co ON c.course_id = co.id
                  WHERE c.user_id = ?
                  ORDER BY c.issued_at DESC",
                 [$userId]);
}

/**
 * Hämta certifikat via certifikatnummer (för verifiering)
 */
function getCertificateByNumber($certNumber) {
    return queryOne("SELECT c.*, u.email as user_email, co.certificate_image_url
                     FROM " . DB_DATABASE . ".certificates c
                     JOIN " . DB_DATABASE . ".users u ON c.user_id = u.id
                     LEFT JOIN " . DB_DATABASE . ".courses co ON c.course_id = co.id
                     WHERE c.certificate_number = ?",
                    [$certNumber]);
}

/**
 * Hämta dashboard-data för en användare
 */
function getDashboardData($userId) {
    $stats = getUserStats($userId);

    // Beräkna nivå baserat på XP (100 XP per nivå, ökar med 50 per nivå)
    $totalXp = (int)$stats['total_xp'];
    $level = 1;
    $xpForNextLevel = 100;
    $xpInCurrentLevel = $totalXp;

    while ($xpInCurrentLevel >= $xpForNextLevel) {
        $xpInCurrentLevel -= $xpForNextLevel;
        $level++;
        $xpForNextLevel = 100 + ($level - 1) * 50;
    }

    // Hämta senaste badges (max 5)
    $recentBadges = query("SELECT b.* FROM " . DB_DATABASE . ".user_badges ub
                           JOIN " . DB_DATABASE . ".badges b ON ub.badge_id = b.id
                           WHERE ub.user_id = ?
                           ORDER BY ub.earned_at DESC
                           LIMIT 5",
                          [$userId]);

    // Hämta kursframsteg
    $courseProgress = query("SELECT
                                c.id, c.title, c.image_url,
                                COUNT(DISTINCT l.id) as total_lessons,
                                COUNT(DISTINCT CASE WHEN up.status = 'completed' THEN l.id END) as completed_lessons
                             FROM " . DB_DATABASE . ".courses c
                             JOIN " . DB_DATABASE . ".lessons l ON c.id = l.course_id AND l.status = 'active'
                             LEFT JOIN " . DB_DATABASE . ".user_progress up ON l.id = up.lesson_id AND up.user_id = ?
                             WHERE c.status = 'active'
                             GROUP BY c.id
                             HAVING completed_lessons > 0
                             ORDER BY (completed_lessons / total_lessons) DESC, c.title
                             LIMIT 5",
                            [$userId]);

    // Hämta aktivitetshistorik (senaste 14 dagarna)
    $activityHistory = [];
    for ($i = 13; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $activity = queryOne("SELECT * FROM " . DB_DATABASE . ".daily_activity
                              WHERE user_id = ? AND activity_date = ?",
                             [$userId, $date]);
        $activityHistory[] = [
            'date' => $date,
            'lessons' => $activity ? (int)$activity['lessons_completed'] : 0,
            'xp' => $activity ? (int)$activity['xp_earned'] : 0
        ];
    }

    return [
        'stats' => $stats,
        'level' => $level,
        'xp_in_level' => $xpInCurrentLevel,
        'xp_for_next_level' => $xpForNextLevel,
        'recent_badges' => $recentBadges,
        'course_progress' => $courseProgress,
        'activity_history' => $activityHistory,
        'total_badges' => count(getUserBadges($userId))
    ];
}

/**
 * Beräkna XP-nivå
 */
function calculateLevel($totalXp) {
    $level = 1;
    $xpForNextLevel = 100;
    $xpRemaining = $totalXp;

    while ($xpRemaining >= $xpForNextLevel) {
        $xpRemaining -= $xpForNextLevel;
        $level++;
        $xpForNextLevel = 100 + ($level - 1) * 50;
    }

    return [
        'level' => $level,
        'xp_in_level' => $xpRemaining,
        'xp_for_next_level' => $xpForNextLevel,
        'progress_percent' => round(($xpRemaining / $xpForNextLevel) * 100)
    ];
}
