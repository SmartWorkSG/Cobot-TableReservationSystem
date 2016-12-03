<?php
ini_set('display_errors', 'On');

require_once __DIR__ . '/vendor/autoload.php';

$config = require_once __DIR__ . '/config/config.php';

// Check if code is sent
if(isset($_GET['code'])) {
	$code = $_GET['code'];
	$client = new GuzzleHttp\Client();
	$res = $client->request(
		'POST',
		'https://www.cobot.me/oauth/access_token',
		[
			'form_params' => [
				'client_id' => $config['clientId'],
				'client_secret' => $config['clientSecret'],
				'grant_type' => 'authorization_code',
				'code' => $code,
			]
		]
	);
	$response = json_decode($res->getBody()->getContents(), true);
	if(isset($response['access_token'])) {
		$client = new GuzzleHttp\Client();
		$res = $client->request(
			'GET',
			'https://www.cobot.me/api/user',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $response['access_token'],
				],
			]
		);
		$response = json_decode($res->getBody()->getContents(), true);
		if(isset($response['id'])) {
			// Now get the membership ID for the user
			$client = new GuzzleHttp\Client();
			$res = $client->request(
				'GET',
				'https://smartspace.cobot.me/api/memberships',
				[
					'headers' => [
						'Authorization' => 'Bearer ' . $config['accessToken'],
					],
				]
			);
			$members = json_decode($res->getBody()->getContents(), true);
			foreach($members as $member) {
				if($member['user']['id'] === $response['id']) {
					setcookie('auth', hash_hmac('sha512', $member['id'], $config['accessToken']));
					setcookie('auth_plain', $member['id']);
					break;
				}
			}

		}
	}
}

// Check if cookie is sent with the request
if(isset($_COOKIE['auth']) && isset($_COOKIE['auth_plain'])) {
	if(hash_hmac('sha512', $_COOKIE['auth_plain'], $config['accessToken']) === $_COOKIE['auth']) {
		$loggedInUser = $_COOKIE['auth_plain'];
	} else {
		// Redirect to auth page
		unset($_COOKIE['auth']);
		unset($_COOKIE['auth_plain']);
		setcookie('auth', null, -1, '/');
		setcookie('auth_plain', null, -1, '/');
		header('Location: https://www.cobot.me/oauth/authorize?response_type=code&client_id='.$config['clientId'].'&redirect_uri='.$config['redirectUri'].'&state=&scope=read_user');
		exit();
	}

} else {
	// Redirect to auth page
	header('Location: https://www.cobot.me/oauth/authorize?response_type=code&client_id='.$config['clientId'].'&redirect_uri='.$config['redirectUri'].'&state=&scope=read_user');
	exit();
}

function getTables(array $config) {
	$client = new GuzzleHttp\Client();
	$res = $client->request(
		'GET',
		'https://smartspace.cobot.me/api/resources',
		[
			'headers' => [
				'Authorization' => 'Bearer ' . $config['accessToken'],
			],
		]
	);
	$tablesInSystem = json_decode($res->getBody()->getContents(), true);
	$tables = [];
	foreach($tablesInSystem as $table) {
		if(substr($table['name'], 0, 4) === 'FIX_') {
			array_push($tables, $table);
		}

	}

	$sortedTables = [];
	foreach($tablesInSystem as $table) {
		$sortedTables[$table['id']] = $table;
	}
	return $sortedTables;
}

function getBookings(array $config) {
	$client = new GuzzleHttp\Client();
	$currentDate = new DateTime();
	$interval = new DateInterval('P1M');
	$from = $currentDate->sub($interval)->format('Y-m-d');
	$to = $currentDate->add($interval)->format('Y-m-d');

	$res = $client->request(
		'GET',
		sprintf(
			'https://smartspace.cobot.me/api/bookings?from=%s&to=%s',
			$from,
			$to
		),
		[
			'headers' => [
				'Authorization' => 'Bearer ' . $config['accessToken'],
			],
		]
	);
	return json_decode($res->getBody()->getContents(), true);
}

$tables = getTables($config);
$bookings = getBookings($config);
$relevantBookings = [];
$state = 'Flex';
foreach($tables as $table) {
	$explode = explode('_', $table['name']);
	$ownerId = array_pop($explode);
	if($ownerId === $loggedInUser) {
		$state = 'Fix';
		break;
	}
}
foreach($bookings as $booking) {
	if(substr($booking['resource_name'], 0, 4) === 'FIX_') {
		array_push($relevantBookings, $booking);
	}
}
$bookingsByDate = [];
foreach($relevantBookings as $booking) {
	$utc = new DateTimeZone('Europe/Zurich');
	$date = new DateTime($booking['from'], $utc);
	$date->setTimezone(new DateTimeZone('Europe/Zurich'));
	$bookingsByDate[$date->format('Y-m-d')][] = $booking;
}

if(isset($_GET['action'])) {
	if (parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST) !== $_SERVER['HTTP_HOST']) {
		die('Potential CSRF detected.');
	}

	$action = $_GET['action'];
	switch($action) {
		case 'free':
			// Look for the table that the user owns
			foreach($tables as $table) {
				$tableOwner = substr($table['name'], -strlen($loggedInUser));
				if($tableOwner === $loggedInUser) {
					$bookingUrl = $table['booking_url'];

					$client = new GuzzleHttp\Client();
					$res = $client->request(
						'POST',
						$bookingUrl,
						[
							'form_params' => [
								'from' => $_GET['from'],
								'to' => $_GET['to'],
								'title' => 'Free',
								//'membership_id' => $loggedInUser,
							],
							'headers' => [
								'Authorization' => 'Bearer ' . $config['accessToken'],
							],
						]
					);
					if($res->getStatusCode() === 201) {
						header('Location: '.$config['redirectUri']);
					}
					break;
				}
			}
			break;
		case 'book':
			$bookingId = $_GET['id'];
			// First check whether the user has still passes
			$client = new GuzzleHttp\Client();
			$res = $client->request(
				'GET',
				'https://smartspace.cobot.me/api/memberships/'.$loggedInUser.'/time_passes/unused',
				[
					'headers' => [
						'Authorization' => 'Bearer ' . $config['accessToken'],
					],
				]
			);
			$timePasses = json_decode($res->getBody()->getContents(), true);
			if(count($timePasses) > 0) {
				// Get the table for the ID
				$activeBooking = '';
				foreach($relevantBookings as $booking) {
					if($bookingId === $booking['id']) {
						if($booking['comments'] !== null) {
							die('Already booked');
						}
						$table = $tables[$booking['resource']['id']];
						$explode = explode('_', $table['name']);
						$ownerId = array_pop($explode);
						$activeBooking = $booking;
					}
				}

				if(!is_array($activeBooking)) {
					die('No active booking found.');
				}

				// Delete the first time pass
				$id = $timePasses[0]['id'];
				$client = new GuzzleHttp\Client();
				$client->request(
					'DELETE',
					'https://smartspace.cobot.me/api/memberships/'.$loggedInUser.'/time_passes/'.$id,
					[
						'headers' => [
							'Authorization' => 'Bearer ' . $config['accessToken'],
						],
					]
				);

				// Update the booking to have the booker ID as comment
				$client = new GuzzleHttp\Client();
				$client->request(
					'PUT',
					'https://smartspace.cobot.me/api/bookings/'.$bookingId,
					[
						'headers' => [
							'Authorization' => 'Bearer ' . $config['accessToken'],
						],
						'form_params' => [
							'comments' => $loggedInUser,
						],
					]
				);

				// Give the owner some credits for booking
				$client = new GuzzleHttp\Client();
				$client->request(
					'POST',
					'https://smartspace.cobot.me/api/memberships/'.$ownerId.'/charges',
					[
						'headers' => [
							'Authorization' => 'Bearer ' . $config['accessToken'],
						],
						'form_params' => [
							'description' => 'Tischfreigabe '.$bookingId,
							'amount' => '-10',
							'charged_at' => date('Y-m-d'),
							'quantity' => '1.0',
						],
					]
				);
				die('Erfolgreich gebucht! <a href="'.$config['redirectUri'].'">Zurück zum Buchungsinterface</a>');
			} else {
				die('Keine Day Passes übrig. Bitte erwerbe neue.');
			}
			break;
	}

	exit();
}

?>

<!DOCTYPE html>
<html>
<head>
	<meta charset='utf-8' />
	<link href='./js/fullcalendar/fullcalendar.css' rel='stylesheet' />
	<link href='./js/fullcalendar/fullcalendar.print.css' rel='stylesheet' media='print' />
	<script src='./js/fullcalendar/lib/moment.min.js'></script>
	<script src='./js/fullcalendar/lib/jquery.min.js'></script>
	<script src='./js/fullcalendar/fullcalendar.min.js'></script>

	<script>
		window.Cobot = window.Cobot || {};
		window.Cobot.iframeResize = function(height) {
			if(window.top != window) {
				window.parent.postMessage(JSON.stringify({frameHeight: height || window.Cobot.iframeHeight()}), '*');
			}
		};
		window.Cobot.iframeHeight = window.Cobot.iframeHeight || function() {
				var height = document.body.offsetHeight;
				var style = getComputedStyle(document.body);
				height += parseInt(style.marginTop) + parseInt(style.marginBottom);
				return height;
			};
		if(window.top != window) {
			window.addEventListener('load', function() {
				window.Cobot.iframeResize();
			});
		}
		window.Cobot.scrollTop = 0;
		window.addEventListener('message', function(message) {
			try {
				var data = JSON.parse(message.data);
				if(data.scrollTop) {
					window.Cobot.scrollTop = data.scrollTop;
				}
			} catch(e) {
				// invalid json, ignore
			}
		}, false);
	</script>
	<script>

		$(document).ready(function() {

			$('#calendar').fullCalendar({
				header: {
					left: 'prev,next today',
					center: 'title',
					right: ''
				},
				<?php if($state === 'Fix'): ?>
				selectable: true,
				<?php else: ?>
				selectable: false,
				<?php endif; ?>
				selectHelper: true,
				select: function(start, end, allDay) {
					var check = $.fullCalendar.moment(start);
					var today = $.fullCalendar.moment(new Date());
					if(check < today)
					{
						// Previous Day
					}
					else
					{
						check.stripTime();
						var confirmation = prompt('Willst du deinen Tisch wirklich am '+check.format()+' freigeben?\nTippe "JA" um fortzufahren. Dies ist unwiderruflich.');
						if (confirmation === 'JA') {
							window.open('./index.php?action=free&from='+check.format()+' 00:00 CEST&to='+check.format()+' 23:59 CEST', '_self')
						}
					}
				},
				selectConstraint:{
					start: '00:00',
					end: '24:00'
				},
				editable: false,
				eventLimit: true, // allow "more" link when too many events
				events: [
					<?php
					foreach($bookingsByDate as $date => $bookings) {
						$freeTables = 0;
						foreach($bookings as $booking) {
							if($booking['comments'] === null) {
								$freeTables++;
							}
						}
						if($freeTables === 0) {
							continue;
						}

						foreach($bookings as $booking) {
							$bookingToPrint = [
								'title' => 'Buche '.$tables[$booking['resource']['id']]['description'].' Tisch',
								'start' => $date,
								'allDay' => 'true',
							];

							if($state === 'Flex') {
								$bookingToPrint['url'] = $config['redirectUri'].'?action=book&id='.$booking['id'];
							}

							echo json_encode($bookingToPrint) . ',';
						}

						$background = [
							'start' => str_replace('/', '-', $date),
							'overlap' => 'true',
							'rendering' => 'background',
							'color' => 'green',
						];
						echo json_encode($background) . ',';
					}

					?>

				]
			});

		});

	</script>
	<style>
		.fc-widget-content {
			background-color: #ff9f89;
		}
		.fc-bgevent {
			opacity: 1 !important;
		}
		body {
			margin: 40px 10px;
			padding: 0;
			font-family: "Lucida Grande",Helvetica,Arial,Verdana,sans-serif;
			font-size: 14px;
		}

		#calendar {
			max-width: 900px;
			margin: 0 auto;
		}

	</style>
</head>
<body>

<div style="max-width: 900px; margin: 0 auto">
<?php
if($state === 'Fix'):
?>
<p>Als Co-Worker kannst du hier deinen Tisch freigeben. Um diesen freizugeben wähle bitte die Daten an denen du <strong>sicher nicht</strong> anwesend bist.</p>
<?php else: ?>
<p>Bitte wähle einen Tisch zur Buchung.</p>
<?php endif; ?>
</div>
<div id='calendar'></div>

</body>
</html>

