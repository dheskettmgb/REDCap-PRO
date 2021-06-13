<?php
$role = $module->getUserRole(USERID); // 3=admin/manager, 2=monitor, 1=user, 0=not found
if (SUPER_USER) {
    $role = 3;
}
if ($role > 0) {
    
    echo "<title>".$module::$APPTITLE." - Manage</title>";
    require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
    $module->UiShowHeader("Manage");

    $proj_id = $module->getProjectId($project_id);

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        try {
            $function = empty($_POST["toDisenroll"]) ? "reset" : "disenroll";
            if ($function === "reset") {
                $result = $module->sendPasswordResetEmail($_POST["toReset"]);
                $icon = "success";
                $msg = "Successfully reset password for participant.";
            } else {
                $result = $module->disenrollParticipant($_POST["toDisenroll"], $proj_id);
                if (!$result) {
                    $icon = "error";
                    $msg = "Trouble disenrolling participant.";
                } else {
                    $icon = "success";
                    $msg = "Successfully disenrolled participant from project.";
                }
            }
            $title = $msg;
        }
        catch (\Exception $e) {
            $icon = "error";
            $title = "Failed to ${function} participant.";
        }
    }

    // Get list of participants
    // TODO: seriously... project_id and proj_id... figure something out
    $participantList = $module->getProjectParticipants($proj_id);


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>REDCap PRO - Manage</title>
    <style>
        .wrapper { 
            display: inline-block; 
            padding: 20px; 
        }
        .manage-form {
            border-radius: 5px;
            border: 1px solid #cccccc;
            padding: 20px;
            box-shadow: 0px 0px 5px #eeeeee;
        }
        #RCPRO_Manage_Users tr.even {
            background-color: #f0f0f0 !important;
        }
        #RCPRO_Manage_Users tr.odd {
            background-color: white !important;
        }
    </style>
</head>
<body>

<?php if ($_SERVER["REQUEST_METHOD"] == "POST") { ?>
    <script>
        Swal.fire({icon: "<?=$icon?>", title:"<?=$title?>"});
    </script>
<?php } ?>

    <div class="manageContainer wrapper">
        <h2>Manage Study Participants</h2>
        <p>Reset passwords, disenroll from study, etc.</p>
        <form class="manage-form" id="manage-form" action="<?= $module->getUrl("manage.php"); ?>" method="POST" enctype="multipart/form-data" target="_self">
<?php if (count($participantList) === 0) { ?>
                <div>
                    <p>No participants have been enrolled in this study</p>
                </div>
<?php } else { ?>
                <div class="form-group">
                    <table class="table" id="RCPRO_Manage_Users">
                        <thead>
                            <tr>
                                <th>Username</th>
<?php if ($role > 1) { ?>
                                <th>First Name</th>
                                <th>Last Name</th>
                                <th>Email</th>
<?php } ?>
                                <th>Reset Password</th>
<?php if ($role > 1) { ?>
                                <th>Disenroll</th>
<?php } ?>
                            </tr>
                        </thead>
                        <tbody>
<?php foreach ($participantList as $participant) { ?>
                            <tr>
                                <td><?=$participant["username"]?></td>
<?php if ($role > 1) { ?>
                                <td><?=$participant["fname"]?></td>
                                <td><?=$participant["lname"]?></td>
                                <td><?=$participant["email"]?></td>
<?php } ?>
                                <td><button type="button" class="btn btn-primary" onclick='(function(){
                                    $("#toReset").val("<?=$participant["id"]?>");
                                    $("#toDisenroll").val("");
                                    $("#manage-form").submit();
                                    })();'>Reset</button></td>
<?php if ($role > 1) { ?>
                                <td><button type="button" class="btn btn-secondary" onclick='(function(){
                                    $("#toReset").val("");
                                    $("#toDisenroll").val("<?=$participant["id"]?>");
                                    $("#manage-form").submit();
                                    })();'>Disenroll</button></td>
<?php } ?>
                            </tr>
<?php } ?>
                        </tbody>
                    </table>
                </div>
                <input type="hidden" id="toReset" name="toReset">
                <input type="hidden" id="toDisenroll" name="toDisenroll">        
        </form>
            
<?php } ?>
    </div>
    <script>
        $('#RCPRO_Manage_Users').DataTable();
    </script>
</body>




<?php
}
include APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';