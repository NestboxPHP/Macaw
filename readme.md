# Titmouse

An interface for the Microsoft [PlayFab REST API](https://learn.microsoft.com/en-us/gaming/playfab/api-references/).

## Usage

Macaw requries Nestbox to function

```php
$macaw = new Macaw(titleId: "abc123");
$leaderboard = $macaw->get_character_leaderboard(startPosition: 0, statisticName: "stat_name_here");
```

### Settings

| Setting                    | Description                                                                 | Default   |
|----------------------------|-----------------------------------------------------------------------------|-----------|
| macawStaleHoursNews        | Defines the time in hours between cached news data updates.                 | `1`       |
| macawStaleHoursTitleData   | Defines the time in hours between cached title data updates.                | `1`       |
| macawStaleHoursCatalog     | Defines the time in hours between cached catalog data updates.              | `168`     |
| macawStaleHoursLeaderboard | Defines the time in hours between cached leaderboard data updates.          | `24`      |
| macawClient2MinLimit       | Defines the maximum API request calls per 2 minute range for client.        | `1000`    |
| macawServer2MinLimit       | Defines the maximum API request calls per 2 minute range for server.        | `12000`   |
| macawSessionKey            | Defines the `$_SESSION` key which is used to store and access PlayFab data. | `playfab` |

## Methods

### Session Tickets

Session tickets are used by the PlayFab API to authenticate each REST call. Once retrieved, they are persistent until 
they expire, after which the class must reauthenticate. The first time the class authenticates must be through one of 
the login functions:

- `login_with_email_address()`
- `login_with_google_account()`

After the class has been authenticated, the login details are stored to be reused once the session ticket has expired.
Once the class detects the session ticket has expired (via `session_ticket_is_expired()`), it will use `relogin_user()`
to reauthenticate and use the stored login details to pass the credentials without having to re-call the original login
method.
