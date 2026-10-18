# User Acceptance Test — checklist

One scenario for each acceptance criterion in the SRS (section 6). Each
scenario says who signs in, what to do, step by step, and what you should
see. Tick **Pass** or **Fail**; if something is different, write what you
saw in **Notes** (a screenshot helps).

| Tester | Date | System address | Language(s) tested |
|---|---|---|---|
| | | | |

## Before you start

- You are given the system's web address and these accounts (password for
  all of them: given to you separately):

  | Account | Who it is | Works in |
  |---|---|---|
  | admin@example.test | Administrator | all warehouses |
  | manager@example.test | Maya Manager (approves) | all warehouses |
  | operator@example.test | Omar Operator | Beirut Main only |
  | dataentry@example.test | Dina Data Entry (imports items) | all warehouses |
  | auditor@example.test | Adam Auditor (reads everything, changes nothing) | all warehouses |

- The demo data already has stock for the customer **Acme Foods** in
  **Beirut Main** (for example Canned Beans, Yogurt) and for **Cedar Trading**
  (for example Espresso Machine, which has serial numbers).
- The warehouse you are working in is shown at the top of every page. To
  change it, click it and choose another one.
- To sign out: click your name at the top, then **Sign out**.
- Nothing you do here affects real stock: this is a test system.

---

## 1. Receiving in boxes is counted in pieces

*SRS: "An IN of a mixed-unit product converts correctly to base units and updates the balance."*

**Sign in as:** operator@example.test

1. In the menu on the side, click **Stock**. Find **Canned Beans 400g**
   (Acme Foods). Write down the quantity in pieces: ________
2. In the menu, click **Receive**, then **New Receipt**.
3. **Customer:** choose **Acme Foods**.
4. In the first line, type `beans` in **Item** and choose **Canned Beans 400g**.
5. **Unit:** choose **Box**. **Quantity:** type `2`.
6. Under the quantity you should see **= 24 Piece**.
7. The item tracks expiry: fill the **Expiry date** about six months from
   today (there is no batch number to enter).
8. Click **Save Receipt**.

**Expected**

- [ ] The receipt opens with a reference such as REC-2026-000123 and the status **Completed**.
- [ ] Back on **Stock**, Canned Beans shows **24 pieces more** than in step 1.
- [ ] The "larger units" column shows the total in boxes (with the demo data: "22 Box").

Pass ☐ Fail ☐ Notes:

---

## 2. You cannot issue more than there is

*SRS: "An OUT that would exceed available stock is rejected with a clear error, and no partial movement is recorded."*

**Sign in as:** operator@example.test

1. In the menu, click **Issue**, then **New Issue**. **Customer:** **Acme Foods**.
2. First line: **Sugar 1kg**. Look at the grey hint under the quantity:
   "Available: …". Write it down: ________
3. In the quantity, type a number **larger** than what is available.
4. Add a second line with **Canned Beans 400g**, quantity `1`.

**Expected**

- [ ] As soon as you type the too-large quantity, a red message under it says
      only so much is available.
- [ ] The **Save** button stays greyed out; you cannot save.
- [ ] Change the quantity to one that fits: the red message disappears and Save works.
- [ ] Before you correct it, nothing was saved: **Issue** list does not show a new document, and
      **Stock** shows Sugar and Canned Beans unchanged.

Pass ☐ Fail ☐ Notes:

---

## 3. A transfer, including one that arrives short

*SRS: "A transfer dispatched from Warehouse A and received at Warehouse B correctly moves stock, including a short-receipt scenario."*

**Sign in as:** manager@example.test, working in **Beirut Main**.

1. Note on **Stock** the quantity of **Rice 5kg** in Beirut Main: ________
2. **Transfer** → **New Transfer**. **Customer:** Acme Foods. **To warehouse:** **Tripoli**.
3. Line: **Rice 5kg**, **10** bags. Click **Send to Tripoli**.
4. The transfer shows **In transit**. On **Stock**, Beirut Main now has 10 bags fewer.
5. At the top, switch the warehouse to **Tripoli**. Open **Transfer**; set the
   filter to **Incoming** and open the transfer.
6. In **Received**, change 10 to **8**. A **Reason** list appears: choose **Missing**.
7. Click **Confirm receipt**.

**Expected**

- [ ] Before step 7, the page says the stock can't be changed once confirmed.
- [ ] The transfer is now **Completed**; the line shows Sent 10, Received 8, with "short 2 — Missing".
- [ ] **Stock** in Tripoli shows **8** Rice 5kg; Beirut Main still shows 10 fewer than in step 1.
- [ ] **Reports** → **Transfer shortages** lists this line with the reason **Missing**.

Pass ☐ Fail ☐ Notes:

---

## 4. Damage: small amounts post, large ones need a manager — never your own

*SRS: "A Damage document below the configured threshold posts directly; one at or above threshold requires Manager approval, and the requester cannot self-approve."*

**Sign in as:** admin@example.test

1. **Settings** → **Approval limits**. Set **Damage limit** to `10`. Save.
2. Sign out. **Sign in as:** operator@example.test.
3. **Damage** → **New Damage**. Customer **Acme Foods**, **Reason** "Damaged in handling".
   Line: **Sugar 1kg**, quantity `2`. Click **Save Damage**.
4. Expected: the document is **Completed** straight away.
5. **New Damage** again, same customer and reason. Line: **Sugar 1kg**, quantity `12`.
6. Expected: a notice says a manager must approve it, and the button reads
   **Send for approval**. Click it.
7. Sign out. **Sign in as:** manager@example.test.
8. In the side menu, **Damage** shows a small number (documents waiting for you).
   Open the waiting document and click **Approve and update stock**.

**Expected**

- [ ] Step 4: Completed at once; Sugar went down by 2.
- [ ] Step 6: the document is **Pending approval**; Sugar did **not** change yet.
- [ ] Step 8: it becomes **Completed** and Sugar goes down by 12.
- [ ] Self-approval: as manager, create a Damage of 12 yourself. Open it: instead of
      Approve / Reject it says another manager must approve it.

Pass ☐ Fail ☐ Notes:

---

## 5. Expired stock is not issued, and the expiry report finds what expires soon

*SRS: "An expiry-tracked product blocks issuing expired stock without Manager override, and the expiry report correctly lists near-expiry stock."*

**Sign in as:** manager@example.test, working in Beirut Main.

1. **Reports** → **Expiry**. Keep "Expired and expiring" and 30 days.
2. Expected: **Yogurt 500g** that expired 3 days ago shows **Expired** (red), and the
   Yogurt expiring in 4 days shows "< 30 days" (amber).
3. **Issue** → **New Issue**, Customer Acme Foods, line **Yogurt 500g**.
   The "Available" hint does **not** include the 12 expired pieces.
4. Type a quantity larger than the available (non-expired) amount.

**Expected**

- [ ] Step 2 as described.
- [ ] Step 4: a red message under the quantity says only the non-expired amount is
      available. Save stays greyed out.
- [ ] When you issue a smaller amount, the details page shows which expiry date it was
      taken from: the one that expires first (in 4 days).

> **Note for the client:** expired stock is always refused; there is **no manager
> override** in this version. Expired stock leaves through **Damage** (reason
> "Expired"). If an override is required, record this scenario as **Fail** so it
> is planned.

Pass ☐ Fail ☐ Notes:

---

## 6. Serial numbers: one per piece, only ones in stock

*SRS: "A serial-tracked product enforces one serial per unit and prevents issuing a serial not currently in stock."*

**Sign in as:** manager@example.test, in Beirut Main.

1. **Receive** → **New Receipt**, Customer **Cedar Trading**, item **Espresso Machine**.
2. Expected: there is no unit choice (only Piece). Type two serial numbers, one per line:
   `UAT-SN-1` and `UAT-SN-2`. The quantity shows **2**.
3. Try typing `UAT-SN-1` twice, or the existing `EM-2026-0001`: a red message says it
   is entered twice / already in stock. Fix it and save.
4. **Issue** → **New Issue**, Cedar Trading, Espresso Machine.

**Expected**

- [ ] Step 4: you **tick** serial numbers from a list of the ones in stock here only
      (EM-2026-0001 … 0003, UAT-SN-1, UAT-SN-2); you cannot type others.
- [ ] Ticking 2 serials makes the quantity 2. Save, and the details show which serials left.
- [ ] Issue again: the serials you just issued are no longer in the list.

Pass ☐ Fail ☐ Notes:

---

## 7. Importing items from Excel: good rows in, bad rows explained

*SRS: "An Excel import of products correctly rejects invalid rows (duplicate SKU, unknown unit) while importing valid rows, with a clear per-row error report."*

**Sign in as:** dataentry@example.test

1. Side menu: **Import** → **Items** → **Download template**. Open it in Excel.
2. Fill three rows (columns with * are required; the second sheet lists the codes):

   | Customer code | Item code | Name (English) | Base unit |
   |---|---|---|---|
   | ACME | UAT-NEW-1 | UAT new item | PC |
   | ACME | CB-400 | Duplicate of Canned Beans | PC |
   | ACME | UAT-NEW-2 | Item with a wrong unit | PCS |

3. Save the file and upload it with **Upload and check**.

**Expected**

- [ ] The page shows **0 ready**, **1 with warnings** and **2 with errors**. The warning is on
      row 2: no Arabic or French name, so the English name will be shown — it is still imported.
      Only these problem rows are listed:
  - [ ] row 3: "Item CB-400 already exists for customer ACME."
  - [ ] row 4: "Base unit “PCS” was not found. Did you mean “PC”?"
- [ ] **Download error report** gives an Excel file with those rows and a Message column.
- [ ] Nothing is saved yet: **Items** does not show UAT-NEW-1.
- [ ] Click **Import 1 row**: UAT-NEW-1 now appears in **Items**; the two bad rows were left out.
- [ ] Upload the same file again: every row now says it already exists; nothing is duplicated.

Pass ☐ Fail ☐ Notes:

---

## 8. Arabic: right to left, with Arabic item names

*SRS: "Switching the UI language to Arabic mirrors the layout to right-to-left and displays translated product names."*

**Sign in as:** operator@example.test

1. At the top, click **عربي**.

**Expected**

- [ ] The whole page mirrors: the menu is on the **right**, text reads right to left.
- [ ] Menus, buttons and messages are in Arabic.
- [ ] **المخزون** (Stock) shows item names in Arabic, e.g. **فول معلّب 400 غ** for Canned Beans.
- [ ] Codes and references stay left to right and unchanged (e.g. CB-400, REC-2026-000123).
- [ ] Open **استلام** (Receive) → new receipt: the form is mirrored and still works.
- [ ] Click **FR**: everything is in French. Click **EN** to go back.

Pass ☐ Fail ☐ Notes:

---

## 9. A user of one warehouse sees and posts only there

*SRS: "A user restricted to one warehouse cannot view or post to another warehouse."*

**Sign in as:** manager@example.test

1. Open the transfer from scenario 3, or any **Tripoli** document, and copy its
   address from the browser's address bar.
2. Sign out. **Sign in as:** operator@example.test (Beirut Main only).

**Expected**

- [ ] The warehouse selector at the top offers **only Beirut Main**.
- [ ] **Stock**, lists and reports show Beirut Main only ("All my warehouses" is Beirut Main).
- [ ] Paste the Tripoli address from step 1: the page says it was **not found**.
- [ ] In **New Transfer** you can send *from* Beirut Main, but you cannot receive
      anything at another warehouse.

Pass ☐ Fail ☐ Notes:

---

## 10. The audit log records who did what, and when

*SRS: "The audit log records every posted, approved and rejected document with the correct user and timestamp."*

**Sign in as:** auditor@example.test

1. **Reports** → **Audit log**.
2. Find the entries for what you did in scenarios 1–4.

**Expected**

- [ ] The receipt of scenario 1: **Completed** by Omar Operator, with the date and time.
- [ ] The large damage of scenario 4: **Sent for approval** by Omar Operator, then
      **Approved** by Maya Manager (two entries, two people, two times).
- [ ] Reject one: as manager, reject a pending Damage with a reason; as auditor,
      the log shows **Rejected** by the manager, and "Before → after" shows the reason.
- [ ] The **Before → after** column reads in words (e.g. "Status: Pending approval → Completed").
- [ ] Export the log with **Excel** and **PDF**: the files contain the same rows.

Pass ☐ Fail ☐ Notes:

---

## Sign-off

| | Name | Signature | Date |
|---|---|---|---|
| Tested by | | | |
| Accepted for the client | | | |
