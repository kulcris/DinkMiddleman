# dinkmiddleman

This sends Dink notifications into Discord.

It can:

- only allow people who are on your roster
- read that roster from a Google Sheet
- quietly tag the correct Discord member
- split events into forum threads like Loot, Pets, Clues, and so on

## Quick Start

If you want the easiest setup, use a public Google Sheet and the default `gviz` mode. That does not need a Google API key.

### 1. Put the files on your server

Upload these files to your web server:

- [index.php](C:/Users/kulcr/Documents/dinkmiddleman/index.php)
- [config.json](C:/Users/kulcr/Documents/dinkmiddleman/config.json)
- [.htaccess](C:/Users/kulcr/Documents/dinkmiddleman/.htaccess) if your host uses Apache

### 2. Make your config

Create a file named `config.local.json`.

The easiest way is:

1. Copy `config.json`
2. Rename the copy to `config.local.json`
3. Edit only `config.local.json`

### 3. Fill in the 3 important things

Open `config.local.json` and set:

- `urlToken`
- `discordWebhookUrl`
- `rosterLookup.googleSheet.sheetId`

You will probably also want:

- `rosterLookup.googleSheet.sheetName`

### 4. Make your Google Sheet

Create a Google Sheet with columns like this:

| Player | Status | Discord ID | Discord Name |
|---|---|---|---|
| Some RSN | Active | DISCORD_USER_ID_HERE | DiscordUser |

Important:

- `Player` must match the RSN coming from Dink
- `Status` should be `Active` if you are using the default filter
- `Discord ID` should be the real Discord user ID, not just the display name

If your column names are different, update these in `config.local.json`:

- `rosterLookup.columns.player`
- `rosterLookup.columns.status`
- `rosterLookup.columns.discordId`
- `rosterLookup.columns.discordName`

### 5. Make the sheet accessible

If you are using the default easy mode:

- set `rosterLookup.source` to `google_sheet`
- leave `rosterLookup.googleSheet.mode` as `gviz`
- make the sheet public or published so the server can read it

If the sheet is not publicly readable, the no-key method will not work. In that case switch to `values_api` and use an API key.

### 6. Find the Google Sheet ID

In a URL like this:

`https://docs.google.com/spreadsheets/d/YOUR_SHEET_ID_HERE/edit#gid=0`

the sheet ID is:

`YOUR_SHEET_ID_HERE`

Put that into:

- `rosterLookup.googleSheet.sheetId`

### 7. Set your webhook URL

Create a Discord webhook in the channel you want to post to, then paste it into:

- `discordWebhookUrl`

### 8. Pick your secret URL ending

Set:

- `urlToken`

Example:

If `urlToken` is `your-secret-token`, your webhook endpoint becomes something like:

`https://your-site.com/dinkmiddleman/your-secret-token`

### 9. Test it

Send a test payload from Dink to your final URL.

If nothing shows up:

1. Check that the player exists in the sheet
2. Check that their `Status` matches `requiredStatus`
3. Check [logs/dink.log](C:/Users/kulcr/Documents/dinkmiddleman/logs/dink.log) if logging is enabled

## Easiest Config Choice

For most people, these are the best settings:

```jsonc
"rosterLookup": {
  "source": "google_sheet",
  "enabled": true,
  "requiredStatus": "Active",
  "columns": {
    "player": "Player",
    "status": "Status",
    "discordId": "Discord ID",
    "discordName": "Discord Name"
  },
  "googleSheet": {
    "mode": "gviz",
    "sheetId": "PUT_YOUR_SHEET_ID_HERE",
    "sheetName": "Roster"
  }
}
```

## Simple Explanations

### What is `gviz`?

It is the easiest no-API-key Google Sheets option. Your server reads the sheet directly from Google in JSON form.

### What is `values_api`?

That is the official Google Sheets API. It is better for restricted-access setups, but it needs a Google API key.

### What is `csv_export`?

That is the older method that reads the sheet as CSV. It still works, but it is usually not the easiest option anymore.

## Common Changes

### Allow everyone through

If you do not want to use a roster at all:

```jsonc
"rosterLookup": {
  "enabled": false
}
```

### Stop forum thread routing

If you just want everything to post into one normal Discord channel:

```jsonc
"forumRouting": {
  "enabled": false
}
```

### Turn off noisy event types

Set any event type to `false`:

```jsonc
"eventRules": {
  "DEATH": {
    "enabled": false
  }
}
```

## Troubleshooting

### "not in roster"

Usually means one of these:

- the RSN in Dink does not exactly match the `Player` value in the sheet
- the row status is not `Active`
- the wrong sheet tab name is set
- the sheet is not public/published while using `gviz`

### Nothing posts to Discord

Check:

- `discordWebhookUrl` is correct
- the final URL ends with your `urlToken`
- the event type is enabled
- the event is above any minimum value filters

### Google Sheet still will not load

Try these in order:

1. Confirm `sheetId` is correct
2. Confirm `sheetName` matches the tab name exactly
3. Make the sheet public/published if using `gviz`
4. Switch to `values_api` if you want a more locked-down setup

