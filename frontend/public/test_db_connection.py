import mysql.connector
from mysql.connector import Error

try:
    connection = mysql.connector.connect(
        host='localhost',
        user='root',
        password='owen',
        database='medical_ai',
        connection_timeout=300,
        use_pure=True,
        autocommit=True
    )
    if connection.is_connected():
        print("Connected to MySQL server")

except Error as e:
    print(f"Error during connection: {e}")
