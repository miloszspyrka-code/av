const keys = [
  'APP_URL',
  'APP_ENV',
  'SESSION_SECRET',
  'SESSION_COOKIE',
  'DB_HOST',
  'DB_PORT',
  'DB_NAME',
  'DB_USER',
  'DB_PASSWORD',
  'DISCORD_CLIENT_ID',
  'DISCORD_CLIENT_SECRET',
  'DISCORD_REDIRECT_URI',
  'DISCORD_GUILD_ID',
  'DISCORD_REQUIRED_ROLE_ID',
  'DISCORD_BOT_TOKEN',
  'ADMIN_DISCORD_IDS',
  'DISCORD_DEFAULT_PLAN',
  'DISCORD_TRIAL_DAYS',
  'ALLOW_DEMO_LOGIN',
  'MAX_STATE_BYTES',
  'MCP_ENABLED',
  'MCP_WRITES_ENABLED',
  'MCP_PILOT_DISCORD_IDS',
  'MCP_ISSUER',
  'MCP_RESOURCE',
  'MCP_PRIVATE_KEY_PATH',
  'MCP_PUBLIC_KEY_PATH',
  'MCP_ENCRYPTION_KEY_PATH',
  'MCP_ALLOWED_REDIRECT_HOSTS',
  'MCP_ACCESS_TOKEN_TTL',
  'MCP_REFRESH_TOKEN_TTL',
  'MCP_AUTH_CODE_TTL',
  'MCP_BODY_LIMIT',
  'MCP_LOG_FILE'
];

const out = {};
for (const key of keys) {
  const value = process.env[key];
  if (value !== undefined && value !== '') out[key] = value;
}
process.stdout.write(JSON.stringify(out));
