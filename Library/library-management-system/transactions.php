<?php
require_once __DIR__.'/includes/functions.php';
require_login();
$role = current_user()['role'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');
    $uid = (int)current_user()['id'];

    // A requester may cancel only their own pending/approved request.
    if ($act === 'cancel') {
        if ($role === 'user') {
            $id = (int)($_POST['id'] ?? 0);
            $s = $db->prepare("UPDATE transactions SET status='cancelled' WHERE id=? AND user_id=? AND status IN ('pending','approved')");
            $s->bind_param('ii', $id, $uid); $s->execute();
            if ($s->affected_rows) { log_action('transaction_cancelled','transactions',$id,'Cancelled by requester'); flash('Your request was cancelled.'); }
            else flash('Only your own pending or approved requests can be cancelled.','error');
            header('Location: '.url('transactions.php')); exit;
        }
    }

    if ($act === 'request') {
        require_role(['user']);
        $book = (int)($_POST['book_id'] ?? 0);
        $type = (($_POST['type'] ?? 'borrow') === 'reservation') ? 'reservation' : 'borrow';
        $note = trim((string)($_POST['note'] ?? ''));
        if (mb_strlen($note) > 500) $note = mb_substr($note, 0, 500);
        try {
            $db->begin_transaction();
            $s = $db->prepare('SELECT id,available_copies FROM books WHERE id=? FOR UPDATE');
            $s->bind_param('i',$book); $s->execute(); $b=$s->get_result()->fetch_assoc();
            if (!$b) throw new Exception('Book not found.');
            $s=$db->prepare("SELECT COUNT(*) n FROM transactions WHERE user_id=? AND book_id=? AND status IN ('pending','approved','released')");
            $s->bind_param('ii',$uid,$book); $s->execute(); $active=(int)$s->get_result()->fetch_assoc()['n'];
            if ($active) throw new Exception('You already have an active request for this book.');
            if ($type==='borrow' && (int)$b['available_copies'] < 1) throw new Exception('No copies available. Submit a reservation instead.');
            $s=$db->prepare("INSERT INTO transactions(user_id,book_id,type,status,request_note) VALUES(?,?,?,'pending',?)");
            $s->bind_param('iiss',$uid,$book,$type,$note); $s->execute(); $id=(int)$db->insert_id;
            $db->commit(); log_action('request_submitted','transactions',$id,$type); flash('Request submitted and queued for staff review.');
        } catch(Throwable $e) { try{$db->rollback();}catch(Throwable $ignored){} flash($e instanceof mysqli_sql_exception ? 'Unable to submit request. Please try again.' : $e->getMessage(),'error'); }
        header('Location: '.url('transactions.php')); exit;
    }

    require_role(['admin','staff']);
    $id=(int)($_POST['id']??0);
    try {
        $db->begin_transaction();
        $s=$db->prepare('SELECT * FROM transactions WHERE id=? FOR UPDATE'); $s->bind_param('i',$id); $s->execute(); $t=$s->get_result()->fetch_assoc();
        if(!$t) throw new Exception('Transaction not found.');
        if($act==='approve') {
            if($t['status']!=='pending') throw new Exception('Only pending requests can be approved.');
            if($t['type']==='borrow') {
                $s=$db->prepare('SELECT available_copies FROM books WHERE id=? FOR UPDATE'); $s->bind_param('i',$t['book_id']); $s->execute(); $b=$s->get_result()->fetch_assoc();
                if(!$b || (int)$b['available_copies']<1) throw new Exception('No copies available. The user may make a reservation.');
            }
            $s=$db->prepare("UPDATE transactions SET status='approved',approved_by=?,approved_at=NOW(),due_date=DATE_ADD(CURDATE(),INTERVAL 14 DAY) WHERE id=? AND status='pending'"); $s->bind_param('ii',$uid,$id); $s->execute();
        } elseif($act==='reject') {
            if($t['status']!=='pending') throw new Exception('Only pending requests can be rejected.');
            $s=$db->prepare("UPDATE transactions SET status='rejected',approved_by=?,approved_at=NOW() WHERE id=? AND status='pending'"); $s->bind_param('ii',$uid,$id); $s->execute();
        } elseif($act==='cancel') {
            if(!in_array($t['status'],['pending','approved'],true)) throw new Exception('This request can no longer be cancelled.');
            $s=$db->prepare("UPDATE transactions SET status='cancelled' WHERE id=?"); $s->bind_param('i',$id); $s->execute();
        } elseif($act==='release') {
            if($t['status']!=='approved') throw new Exception('Only approved requests can be released.');
            $s=$db->prepare('UPDATE books SET available_copies=available_copies-1 WHERE id=? AND available_copies>0'); $s->bind_param('i',$t['book_id']); $s->execute();
            if(!$s->affected_rows) throw new Exception('No available copies to release.');
            $s=$db->prepare("UPDATE transactions SET status='released',released_at=NOW(),due_date=DATE_ADD(CURDATE(),INTERVAL 14 DAY) WHERE id=? AND status='approved'"); $s->bind_param('i',$id); $s->execute();
            if(!$s->affected_rows) throw new Exception('This request was already processed.');
        } elseif($act==='return') {
            if($t['status']!=='released') throw new Exception('Only released loans can be returned.');
            $s=$db->prepare("UPDATE transactions SET status='returned',returned_at=NOW() WHERE id=? AND status='released'"); $s->bind_param('i',$id); $s->execute();
            if(!$s->affected_rows) throw new Exception('This transaction has already been processed.');
            $s=$db->prepare('UPDATE books SET available_copies=LEAST(total_copies,available_copies+1) WHERE id=?'); $s->bind_param('i',$t['book_id']); $s->execute();
        } else throw new Exception('Invalid transaction action.');
        $db->commit(); log_action('transaction_'.$act,'transactions',$id,'Type: '.$t['type']); flash('Transaction updated successfully.');
    } catch(Throwable $e) { try{$db->rollback();}catch(Throwable $ignored){} flash($e instanceof mysqli_sql_exception?'Database operation failed. Please try again.':$e->getMessage(),'error'); }
    header('Location: '.url('transactions.php')); exit;
}

$q=trim((string)($_GET['q']??'')); $status=(string)($_GET['status']??''); $type=(string)($_GET['type']??'');
$sql="SELECT t.*,u.name user_name,u.email,b.title book_title,staff.name staff_name FROM transactions t JOIN users u ON u.id=t.user_id JOIN books b ON b.id=t.book_id LEFT JOIN users staff ON staff.id=t.approved_by WHERE 1=1"; $types=''; $vals=[];
if($role==='user'){$sql.=' AND t.user_id=?';$types.='i';$vals[]=$uid;}
if($q!==''){$sql.=' AND (b.title LIKE ? OR u.name LIKE ?)';$like='%'.$q.'%';$types.='ss';$vals[]=$like;$vals[]=$like;}
if(in_array($status,['pending','approved','rejected','released','returned','cancelled','expired'],true)){$sql.=' AND t.status=?';$types.='s';$vals[]=$status;}
if(in_array($type,['borrow','reservation'],true)){$sql.=' AND t.type=?';$types.='s';$vals[]=$type;}
$sql.=" ORDER BY CASE WHEN t.status='pending' THEN 0 ELSE 1 END,t.requested_at ASC";
$s=$db->prepare($sql); if($types!=='')$s->bind_param($types,...$vals); $s->execute(); $rows=$s->get_result();
$title='Transactions'; require 'includes/header.php';
?>
<h1><?=($role==='user'?'My requests':'Borrowing & reservation transactions')?></h1>
<div class="panel"><form class="filters" method="get"><div><label>Search book / requester</label><input name="q" value="<?=e($q)?>"></div><div><label>Status</label><select name="status"><option value="">All statuses</option><?php foreach(['pending','approved','rejected','released','returned','cancelled','expired'] as $v):?><option value="<?=$v?>" <?=$status===$v?'selected':''?>><?=$v?></option><?php endforeach;?></select></div><div><label>Type</label><select name="type"><option value="">All types</option><option value="borrow" <?=$type==='borrow'?'selected':''?>>Borrow</option><option value="reservation" <?=$type==='reservation'?'selected':''?>>Reservation</option></select></div><button>Filter</button></form></div>
<div class="panel table-wrap"><table><thead><tr><th>ID / Date</th><th>Requester</th><th>Book / Type</th><th>Status / Due</th><th>Action</th></tr></thead><tbody><?php if(!$rows->num_rows):?><tr><td colspan="5">No transactions found.</td></tr><?php endif;?><?php while($t=$rows->fetch_assoc()):?><tr><td>#<?=$t['id']?><br><?=e($t['requested_at'])?></td><td><?=e($t['user_name'])?><br><span class="muted"><?=e($t['email'])?></span></td><td><?=e($t['book_title'])?><br><span class="badge"><?=e($t['type'])?></span></td><td><span class="badge <?=e(($t['status']==='released'&&$t['due_date']&&$t['due_date']<date('Y-m-d'))?'overdue':$t['status'])?>"><?=e(($t['status']==='released'&&$t['due_date']&&$t['due_date']<date('Y-m-d'))?'overdue':$t['status'])?></span><br>Due: <?=e($t['due_date']??'—')?></td><td><?php if(in_array($role,['admin','staff'],true)):?><?php if($t['status']==='pending'):?><form method="post" class="inline"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="id" value="<?=$t['id']?>"><button name="action" value="approve">Approve</button></form> <form method="post" class="inline"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="id" value="<?=$t['id']?>"><button class="btn danger" name="action" value="reject">Reject</button></form><?php elseif($t['status']==='approved'):?><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="id" value="<?=$t['id']?>"><button name="action" value="release">Release / fulfill</button></form><?php elseif($t['status']==='released'):?><form method="post"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="id" value="<?=$t['id']?>"><button class="btn success" name="action" value="return">Record return</button></form><?php else:?>—<?php endif;?><?php else:?><?php if(in_array($t['status'],['pending','approved'],true)):?><form method="post" data-confirm="Cancel this request?"><input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="id" value="<?=$t['id']?>"><button class="btn danger" name="action" value="cancel">Cancel request</button></form><?php else:?>—<?php endif;?><?php endif;?></td></tr><?php endwhile;?></tbody></table></div><?php require 'includes/footer.php'; ?>
