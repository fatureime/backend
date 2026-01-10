# JWT Keys Setup

This directory should contain the JWT public and private keys.

## Generate Keys

After installing dependencies, run the following command to generate the JWT keys:

```bash
php bin/console lexik:jwt:generate-keypair
```

This will create:
- `private.pem` - Private key for signing tokens
- `public.pem` - Public key for verifying tokens

## Environment Variable

Make sure to set `JWT_PASSPHRASE` in your `.env` file. You can generate a secure passphrase with:

```bash
openssl rand -base64 32
```

Then add to `.env`:
```
JWT_PASSPHRASE=your-generated-passphrase-here
```

## Security Note

- Never commit the private key or passphrase to version control
- Keep the passphrase secure and use different values for dev/prod environments
- The `.gitignore` should exclude `private.pem` and `public.pem`
