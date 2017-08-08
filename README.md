# Limelight Data Migration - Data transport one server to another via API

Limelight Data Migration is used to fetch client's data in one database and duplicate that data to another database with differnt standards and methods structure. The Migrations creates products, customers, orders, campaigns, and much more.

## Getting Started

Authorization credentials provided by Limelight as well as data present in the Limelight database.

### Prerequisites

The API can be achieved successfully via basic http request with POST method. $this->username, $this->password, and $this->domain can be exchanged with credentials provided by Limelight.

```
https://'.$this->domain.'/admin/membership.php?username=johnDoe&password=doe123&domain=constantSales&...';
```

## Running the tests

Testing was achieved through observing the database after rows were created to confirm if the data was inserted correctly according to the database standards.

## Authors

* **Brian Beal** - *Initial work* - [bealdev](https://github.com/bealdev)
