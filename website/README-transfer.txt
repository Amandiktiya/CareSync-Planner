CareSync transfer steps for another PC

Project path:
C:\xampp\htdocs\website

What is already added in this project:
- db.php now checks for project data and can import it automatically
- data.sql contains exported users, patients, medicines, doctor notes, and chat data

How to move this website to another PC:

1. Install XAMPP on the other PC.
2. Copy the full folder:
   C:\xampp\htdocs\website
3. Make sure these are also copied:
   - all PHP/CSS/JS files
   - uploads folder
   - data.sql
4. Start Apache and MySQL in XAMPP.
5. Open this URL:
   http://localhost/website/

How the data restore works:
- If the caresync database does not exist, the app creates it.
- If the tables are empty, the app imports data from data.sql.
- Then the same users, doctors, patients, medicines, and notes become available.

Important notes:
- If the second PC already has different old data inside the same database, automatic import may not run.
- In that case, delete the old caresync database from phpMyAdmin and open the site again.
- Keep the uploads folder, otherwise profile images will not appear.

Files involved:
- db.php
- schema.sql
- data.sql
- uploads\
