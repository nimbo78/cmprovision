# HTTP API

[Back to the README](../README.md)

Create a token on your profile's API tokens page with the permissions your integration needs (`create`, `read`, `update`, `delete`). Send it in the `Authorization` header:

```sh
curl --fail --show-error \
  -H "Authorization: Bearer $CMPROVISION_TOKEN" \
  -H "Accept: application/json" \
  "http://<server-wifi-address>/api/projects"
```

Replace the server address and set `CMPROVISION_TOKEN` to your token before running the example. All routes below require token authentication. Mutations additionally check the corresponding `create`, `update` or `delete` permission.

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/api/cms` | List modules |
| GET | `/api/projects` | List projects with scripts |
| GET | `/api/projects/{id}` | Read a project with scripts |
| GET | `/api/projects/{id}/cms` | List a project's modules |
| GET | `/api/images`, `/api/images/{id}` | List images or read an image |
| GET | `/api/firmware` | List firmware |
| GET | `/api/scripts`, `/api/scripts/{id}` | List scripts or read a script |
| GET | `/api/labels` | List labels |
| POST | `/api/images` | Upload an image using multipart field `image` |
| DELETE | `/api/images/{id}` | Delete an image |
| PATCH | `/api/projects/{id}` | Update project fields and its script selection |
| POST | `/api/scripts` | Create a script |
| PATCH | `/api/scripts/{id}` | Update a script |
| DELETE | `/api/scripts/{id}` | Delete an unused script |

For a project update, `scripts` is the **complete list** of script IDs to attach. Updating an active project rebuilds the EEPROM image that modules download. First-boot scripts and the `firstboot` label moment require `os_check` to be enabled.

Scripts have type `preinstall`, `postinstall` or `firstboot`. Deleting a script still used by a project returns HTTP 409. The request validation and complete field definitions live in [`routes/api.php`](../routes/api.php).
