<?php

declare(strict_types=1);

// Forwards TCP connections to the Redis address given as the first argument and counts round trips: a round trip
// starts when a client sends data after it has received a reply. Prints the listening port first, then the current
// count for every line read from STDIN; exits when STDIN is closed.

$upstreamAddress = $argv[1] ?? '';
$server = stream_socket_server('tcp://127.0.0.1:0');
$address = $server === false ? false : stream_socket_get_name($server, false);

if ($server === false || $address === false || $upstreamAddress === '') {
    exit(1);
}

fwrite(STDOUT, substr($address, (int) strrpos($address, ':') + 1) . "\n");

/** @var array<int, array{client: resource, upstream: resource, answered: bool}> $links */
$links = [];
$roundTrips = 0;

while (true) {
    $read = [$server, STDIN];
    foreach ($links as $link) {
        $read[] = $link['client'];
        $read[] = $link['upstream'];
    }
    $write = null;
    $except = null;

    if (stream_select($read, $write, $except, null) === false) {
        exit(1);
    }

    foreach ($read as $stream) {
        if ($stream === $server) {
            $client = stream_socket_accept($server);
            $upstream = stream_socket_client('tcp://' . $upstreamAddress);
            if ($client !== false && $upstream !== false) {
                $links[(int) $client] = [
                    'client' => $client,
                    'upstream' => $upstream,
                    'answered' => true,
                ];
            }

            continue;
        }

        if ($stream === STDIN) {
            if (fgets(STDIN) === false) {
                exit(0);
            }
            fwrite(STDOUT, $roundTrips . "\n");

            continue;
        }

        foreach ($links as $id => $link) {
            $fromClient = $stream === $link['client'];
            if (! $fromClient && $stream !== $link['upstream']) {
                continue;
            }

            $data = fread($stream, 65536);
            if ($data === false || $data === '') {
                fclose($link['client']);
                fclose($link['upstream']);
                unset($links[$id]);
            } elseif ($fromClient) {
                if ($link['answered']) {
                    ++$roundTrips;
                }
                $links[$id]['answered'] = false;
                fwrite($link['upstream'], $data);
            } else {
                $links[$id]['answered'] = true;
                fwrite($link['client'], $data);
            }
        }
    }
}
