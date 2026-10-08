### Changed

- **`invitations()->accept($id, $token)` takes the single-use token from the invitation link** (B1-761). The API requires it; the signed-in email is no longer compared against the invited one. `AcceptInvitationDto` is generated from the contract.
